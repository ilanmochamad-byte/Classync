// Pencocok.cs — membaca sampel, mengekstrak templat, dan mencocokkan 1:N.
//
// Halaman mengirim sampel dari HID Authentication Device Client apa adanya.
// Berkas ini membacanya, mengekstrak templat dengan SourceAFIS, lalu
// mencocokkannya dengan semua templat di galeri. Hasil yang diterima
// ditandatangani Brankas sebagai "absen".
//
// Aturan yang dipegang:
// - Galeri hanya berisi templat, dimuat dari templat.json yang terenkripsi.
//   Gambar sidik jari tidak pernah ditulis ke disk. Gambar pendaftaran hanya
//   disimpan di memori selama jembatan hidup, untuk kalibrasi DPI.
// - SourceAFIS tidak tahan beda skala, jadi probe dan galeri harus diekstrak
//   dengan DPI yang sama. DPI itu ditulis di kolom versi setiap rekaman,
//   bersama versi pustaka, karena keduanya menentukan apakah dua templat bisa
//   dibandingkan. Contoh: sourceafis-net-3.14.0-700.
// - Identifikasi diterima kalau skor terbaik mencapai Ambang DAN cukup jauh di
//   atas identitas kedua. Ambang 40 yang dianjurkan untuk 1:1 terlalu longgar
//   untuk ratusan templat: setiap perbandingan membawa peluang salah-cocok.
// - Sampel yang sama persis dengan sampel sebelumnya ditolak. Tempelan jari
//   sungguhan tidak pernah menghasilkan byte yang sama, jadi sampel kembar
//   hampir pasti rekaman yang diputar ulang.
// - Rekaman templat.json yang gagal dibuka tidak dibuang diam-diam: ia
//   dihitung, dilaporkan di /status, dan tetap ditulis kembali apa adanya.
//
// Pendaftaran tanpa token server, kalibrasi, ukur waktu, dan hapus data uji
// hanya untuk prototipe 4.1.

using System.Buffers.Binary;
using System.Collections.Immutable;
using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Security.Cryptography;
using System.Text.Json;
using Microsoft.Extensions.Logging;
using SourceAFIS;

sealed class Pencocok
{
    // Ambang identifikasi 1:N dan selisih minimum terhadap identitas kedua.
    // Nilai awal prototipe; angka akhirnya dipilih dari laporan uji di PC
    // kiosk.
    public const double Ambang = 50;
    public const double Selisih = 10;

    // Ambang 1:1 yang dianjurkan SourceAFIS (salah-cocok 0,01%). Dipakai
    // untuk keserasian tempelan satu jari saat mendaftar, dan untuk mencari
    // jari yang sudah terdaftar atas nama lain.
    const double AmbangSatuLawanSatu = 40;

    public const int JumlahTempelan = 4;
    // DPI galeri baru, dan galeri setelah data uji dihapus. 700 adalah DPI yang
    // dilaporkan U.are.U 4500 lewat WebSDK, dan kalibrasi uji 3 Oktober 2026
    // (17 jari, 102 pasangan sama-jari, 2.176 beda-jari) memisahkan jari sama
    // dan jari beda paling lebar di 700: jarak 42,2, sedangkan di 500 hanya
    // 19,5. Galeri yang sudah berisi tetap memakai DPI rekamannya.
    const int DpiBawaan = 700;
    static readonly int[] DpiKalibrasi = [500, 512, 600, 700, 800];
    static readonly int[] UkuranGaleriUkur = [50, 100, 500, 2000];

    static readonly string AwalanVersi = "sourceafis-net-" + VersiPustaka() + "-";

    readonly Brankas _brankas;
    readonly ILogger _log;
    readonly Lock _kunciUbah = new();
    readonly Dictionary<(string Identitas, string Jari, int Urutan), Sampel> _gambar = [];
    readonly PenjagaKembar _kembar = new(20_000);
    ImmutableArray<RekamanTemplat> _rekamanTertolak = [];
    volatile Galeri _galeri = new(DpiBawaan, []);
    volatile Probe? _probeTerakhir;

    Pencocok(Brankas brankas, ILogger log)
    {
        _brankas = brankas;
        _log = log;
    }

    // Memuat galeri dari templat.json. Berkas yang bukan JSON sah melempar
    // JsonException: galeri kosong diam-diam akan membuat semua siswa "tidak
    // dikenali", jadi pemanggil sebaiknya berhenti.
    public static Pencocok Muat(Brankas brankas, ILogger log)
    {
        var pencocok = new Pencocok(brankas, log);
        var entri = ImmutableArray.CreateBuilder<Entri>();
        var tertolak = ImmutableArray.CreateBuilder<RekamanTemplat>();
        int? dpi = null;
        foreach (var rekaman in brankas.BacaTemplat())
        {
            try
            {
                var templat = brankas.Dekripsi(rekaman);
                var dpiRekaman = DpiDariVersi(templat.Versi);
                if (dpiRekaman is null || (dpi is not null && dpiRekaman != dpi))
                {
                    throw new InvalidDataException("versi " + templat.Versi + " tidak cocok dengan galeri");
                }
                dpi = dpiRekaman;
                entri.Add(new Entri(templat.Identitas, templat.Jari, templat.Urutan,
                                    new FingerprintTemplate(templat.Data), rekaman));
            }
            catch (Exception e) when (e is not OutOfMemoryException)
            {
                tertolak.Add(rekaman);
                log.LogWarning("Rekaman templat {Identitas}/{Jari}/{Urutan} tidak bisa dipakai: {Sebab}",
                               rekaman.Identitas, rekaman.Jari, rekaman.Urutan, e.Message);
            }
        }
        pencocok._galeri = new Galeri(dpi ?? DpiBawaan, entri.ToImmutable());
        pencocok._rekamanTertolak = tertolak.ToImmutable();
        log.LogInformation("Galeri dimuat: {Templat} templat, DPI {Dpi}, {Tertolak} rekaman tertolak.",
                           pencocok._galeri.Entri.Length, pencocok._galeri.Dpi, tertolak.Count);
        return pencocok;
    }

    public object Ringkasan()
    {
        var galeri = _galeri;
        return new
        {
            identitas = galeri.Entri.Select(e => e.Identitas).Distinct().Count(),
            templat = galeri.Entri.Length,
            dpi = galeri.Dpi,
            rekaman_tertolak = _rekamanTertolak.Length,
        };
    }

    public object IsiGaleri()
    {
        var galeri = _galeri;
        HashSet<(string, string)> punyaGambar;
        lock (_kunciUbah)
        {
            punyaGambar = _gambar.Keys.Select(k => (k.Identitas, k.Jari)).ToHashSet();
        }
        return new
        {
            status = "ok",
            dpi = galeri.Dpi,
            ambang = Ambang,
            selisih = Selisih,
            rekaman_tertolak = _rekamanTertolak.Length,
            jari = galeri.Entri
                .GroupBy(e => (e.Identitas, e.Jari))
                .OrderBy(g => g.Key.Identitas, StringComparer.Ordinal)
                .Select(g => new
                {
                    identitas = g.Key.Identitas,
                    jari = g.Key.Jari,
                    tempelan = g.Count(),
                    gambar_di_memori = punyaGambar.Contains(g.Key),
                }),
        };
    }

    public Hasil Identifikasi(PermintaanIdentifikasi permintaan)
    {
        var mulai = Stopwatch.GetTimestamp();
        if (!Brankas.TantanganSah(permintaan.Tantangan))
        {
            return Hasil.Galat(400, "Tantangan tidak sah.");
        }
        Sampel sampel;
        try
        {
            sampel = Sampel.Baca(permintaan.Format, permintaan.Sampel);
        }
        catch (SampelTidakSahException e)
        {
            return Hasil.Galat(400, e.Message);
        }
        var waktuBaca = Stopwatch.GetElapsedTime(mulai);

        var galeri = _galeri;
        if (galeri.Entri.IsEmpty)
        {
            return Hasil.Galat(409, "Belum ada jari yang terdaftar.");
        }
        if (!_kembar.Tandai(sampel.Sidik))
        {
            return Hasil.Galat(409, "Sampel ini sama persis dengan sampel sebelumnya. Tempelkan jari lagi.");
        }

        FingerprintTemplate probe;
        try
        {
            probe = Ekstrak(sampel, galeri.Dpi);
        }
        catch (SampelTidakSahException e)
        {
            return Hasil.Galat(400, e.Message);
        }
        var waktuEkstraksi = Stopwatch.GetElapsedTime(mulai) - waktuBaca;

        var pencocok = new FingerprintMatcher(probe);
        var skor = new Dictionary<string, double>(StringComparer.Ordinal);
        foreach (var e in galeri.Entri)
        {
            var s = pencocok.Match(e.Templat);
            if (!skor.TryGetValue(e.Identitas, out var lama) || s > lama)
            {
                skor[e.Identitas] = s;
            }
        }
        var waktuCocok = Stopwatch.GetElapsedTime(mulai) - waktuBaca - waktuEkstraksi;

        var teratas = skor.OrderByDescending(kv => kv.Value).ThenBy(kv => kv.Key, StringComparer.Ordinal).Take(2).ToArray();
        var terbaik = teratas[0];
        var kedua = teratas.Length > 1 ? teratas[1].Value : (double?)null;
        var diterima = terbaik.Value >= Ambang && (kedua is null || terbaik.Value - kedua.Value >= Selisih);
        var tanda = diterima
            ? _brankas.TandatanganiAbsen(permintaan.Tantangan!, terbaik.Key, (int)Math.Min(Math.Floor(terbaik.Value), 9999))
            : null;
        _probeTerakhir = new Probe(sampel, probe, galeri.Dpi);
        var total = Stopwatch.GetElapsedTime(mulai);

        _log.LogInformation("Identifikasi: {Hasil} {Identitas} skor {Skor:F1}, kedua {Kedua:F1}, {Total:F0} ms.",
                            diterima ? "diterima" : "tidak dikenali", terbaik.Key, terbaik.Value, kedua ?? 0, total.TotalMilliseconds);
        return Hasil.Oke(new
        {
            status = "ok",
            diterima,
            identitas = diterima ? terbaik.Key : null,
            skor = Bulat(terbaik.Value),
            kandidat = teratas.Select(kv => new { identitas = kv.Key, skor = Bulat(kv.Value) }),
            ambang = Ambang,
            selisih = Selisih,
            dpi = galeri.Dpi,
            sampel = InfoSampel(sampel),
            waktu_ms = new { baca = Ms(waktuBaca), ekstraksi = Ms(waktuEkstraksi), cocok = Ms(waktuCocok), total = Ms(total) },
            pesan = tanda?.Pesan,
            tanda_tangan = tanda?.Hmac,
        });
    }

    // Pendaftaran uji: tepat empat tempelan satu jari dalam satu kiriman.
    // Setiap tempelan harus cocok dengan salah satu tempelan lain, dan tidak
    // boleh mirip jari yang sudah terdaftar atas nama identitas lain.
    // Mendaftarkan ulang identitas dan jari yang sama menggantikan yang lama.
    public Hasil Daftarkan(PermintaanDaftar permintaan)
    {
        var mulai = Stopwatch.GetTimestamp();
        if (permintaan.Sampel is null || permintaan.Sampel.Length != JumlahTempelan)
        {
            return Hasil.Galat(400, $"Pendaftaran butuh tepat {JumlahTempelan} tempelan.");
        }
        if (permintaan.Identitas is not string identitas || permintaan.Jari is not string jari)
        {
            return Hasil.Galat(400, "Identitas dan jari wajib diisi.");
        }
        // Diperiksa sebelum ekstraksi, dengan pola yang sama dengan Brankas,
        // supaya identitas yang salah tidak dijawab dengan galat lain.
        if (!Brankas.MetadataSah(identitas, jari, 1, AwalanVersi + _galeri.Dpi))
        {
            return Hasil.Galat(400, "Identitas atau jari tidak sah.");
        }
        var sampel = new Sampel[JumlahTempelan];
        for (var i = 0; i < JumlahTempelan; i++)
        {
            try
            {
                sampel[i] = Sampel.Baca(permintaan.Format, permintaan.Sampel[i]);
            }
            catch (SampelTidakSahException e)
            {
                return Hasil.Galat(400, $"Tempelan ke-{i + 1}: {e.Message}");
            }
            if (_kembar.Terlihat(sampel[i].Sidik) || sampel.Take(i).Any(s => s.Sidik == sampel[i].Sidik))
            {
                return Hasil.Galat(409, $"Tempelan ke-{i + 1} sama persis dengan sampel sebelumnya. Tempelkan jari lagi.");
            }
        }

        lock (_kunciUbah)
        {
            var galeri = _galeri;
            var templat = new FingerprintTemplate[JumlahTempelan];
            for (var i = 0; i < JumlahTempelan; i++)
            {
                try
                {
                    templat[i] = Ekstrak(sampel[i], galeri.Dpi);
                }
                catch (SampelTidakSahException e)
                {
                    return Hasil.Galat(400, $"Tempelan ke-{i + 1}: {e.Message}");
                }
            }

            var keserasian = new double[JumlahTempelan];
            for (var i = 0; i < JumlahTempelan; i++)
            {
                var pencocok = new FingerprintMatcher(templat[i]);
                for (var j = 0; j < JumlahTempelan; j++)
                {
                    if (j != i)
                    {
                        keserasian[i] = Math.Max(keserasian[i], pencocok.Match(templat[j]));
                    }
                }
            }
            var lemah = Enumerable.Range(0, JumlahTempelan).Where(i => keserasian[i] < AmbangSatuLawanSatu).Select(i => i + 1).ToArray();
            if (lemah.Length > 0)
            {
                return Hasil.Galat(422, $"Tempelan ke-{string.Join(", ", lemah)} tidak cocok dengan tempelan lain. Ulangi tempelan itu.",
                                   new { skor_keserasian = keserasian.Select(s => Bulat(s)), tempelan_lemah = lemah });
            }

            string? identitasLain = null;
            double skorLain = 0;
            foreach (var t in templat)
            {
                var pencocok = new FingerprintMatcher(t);
                foreach (var entri in galeri.Entri.Where(x => x.Identitas != identitas))
                {
                    var s = pencocok.Match(entri.Templat);
                    if (s > skorLain)
                    {
                        (skorLain, identitasLain) = (s, entri.Identitas);
                    }
                }
            }
            if (skorLain >= AmbangSatuLawanSatu)
            {
                return Hasil.Galat(409, $"Jari ini mirip jari yang sudah terdaftar atas nama {identitasLain} (skor {Bulat(skorLain)}). Tidak didaftarkan.",
                                   new { identitas_lain = identitasLain, skor = Bulat(skorLain) });
            }

            var versi = AwalanVersi + galeri.Dpi;
            RekamanTemplat[] rekamanBaru;
            try
            {
                rekamanBaru = templat.Select((t, i) => _brankas.Enkripsi(
                    new Templat(identitas, jari, i + 1, versi, t.ToByteArray()))).ToArray();
            }
            catch (ArgumentException)
            {
                return Hasil.Galat(400, "Identitas atau jari tidak sah.");
            }
            var diganti = galeri.Entri.Any(x => x.Identitas == identitas && x.Jari == jari);
            var entriBaru = galeri.Entri
                .Where(x => !(x.Identitas == identitas && x.Jari == jari))
                .Concat(rekamanBaru.Select((r, i) => new Entri(identitas, jari, i + 1, templat[i], r)))
                .ToImmutableArray();
            TulisGaleri(entriBaru);
            _galeri = galeri with { Entri = entriBaru };
            for (var i = 0; i < JumlahTempelan; i++)
            {
                _gambar[(identitas, jari, i + 1)] = sampel[i];
                _kembar.Tandai(sampel[i].Sidik);
            }

            _log.LogInformation("Pendaftaran {Identitas}/{Jari}: keserasian {Keserasian}, jari lain tertinggi {Skor:F1}.",
                                identitas, jari, string.Join(" ", keserasian.Select(s => Bulat(s))), skorLain);
            return Hasil.Oke(new
            {
                status = "ok",
                identitas,
                jari,
                tempelan = JumlahTempelan,
                diganti,
                skor_keserasian = keserasian.Select(s => Bulat(s)),
                skor_jari_lain_tertinggi = Bulat(skorLain),
                dpi = galeri.Dpi,
                sampel = InfoSampel(sampel[0]),
                waktu_ms = Ms(Stopwatch.GetElapsedTime(mulai)),
            });
        }
    }

    // Tanpa "terapkan": skor sama-jari dan beda-jari untuk setiap DPI calon,
    // dari gambar pendaftaran sesi ini. Dengan "terapkan": semua templat
    // galeri diekstrak ulang dengan DPI itu, yang hanya mungkin kalau semua
    // gambarnya masih di memori.
    public Hasil Kalibrasi(PermintaanKalibrasi permintaan)
    {
        lock (_kunciUbah)
        {
            var gambar = _gambar.ToArray();
            if (permintaan.Terapkan is int dpiBaru)
            {
                return Terapkan(dpiBaru);
            }
            if (gambar.Select(g => g.Key.Identitas).Distinct().Count() < 2)
            {
                return Hasil.Galat(409, "Kalibrasi butuh gambar pendaftaran minimal dua identitas dari sesi ini.");
            }
            var hasil = DpiKalibrasi.Select(dpi => HitungKalibrasi(dpi, gambar)).ToArray();
            _log.LogInformation("Kalibrasi DPI dari {Gambar} gambar.", gambar.Length);
            return Hasil.Oke(new
            {
                status = "ok",
                dpi_sekarang = _galeri.Dpi,
                gambar = gambar.Length,
                identitas = gambar.Select(g => g.Key.Identitas).Distinct().Count(),
                hasil,
            });
        }
    }

    Hasil Terapkan(int dpiBaru)
    {
        if (!DpiKalibrasi.Contains(dpiBaru))
        {
            return Hasil.Galat(400, "DPI harus salah satu dari " + string.Join(", ", DpiKalibrasi) + ".");
        }
        var galeri = _galeri;
        var tanpaGambar = galeri.Entri
            .Where(e => !_gambar.ContainsKey((e.Identitas, e.Jari, e.Urutan)))
            .Select(e => e.Identitas + "/" + e.Jari).Distinct().ToArray();
        if (tanpaGambar.Length > 0)
        {
            return Hasil.Galat(409, "Templat berikut tidak punya gambar di memori, jadi tidak bisa diekstrak ulang: "
                                    + string.Join(", ", tanpaGambar) + ". Daftarkan ulang di sesi ini.");
        }
        var versi = AwalanVersi + dpiBaru;
        var entriBaru = new Entri[galeri.Entri.Length];
        Parallel.For(0, entriBaru.Length, i =>
        {
            var e = galeri.Entri[i];
            var templat = Ekstrak(_gambar[(e.Identitas, e.Jari, e.Urutan)], dpiBaru);
            entriBaru[i] = new Entri(e.Identitas, e.Jari, e.Urutan, templat,
                                     _brankas.Enkripsi(new Templat(e.Identitas, e.Jari, e.Urutan, versi, templat.ToByteArray())));
        });
        var baru = entriBaru.ToImmutableArray();
        TulisGaleri(baru);
        _galeri = new Galeri(dpiBaru, baru);
        _probeTerakhir = null;
        _log.LogWarning("DPI galeri diganti menjadi {Dpi}; {Templat} templat diekstrak ulang.", dpiBaru, baru.Length);
        return Hasil.Oke(new { status = "ok", dpi = dpiBaru, templat = baru.Length });
    }

    static object HitungKalibrasi(int dpi, KeyValuePair<(string Identitas, string Jari, int Urutan), Sampel>[] gambar)
    {
        var mulai = Stopwatch.GetTimestamp();
        var templat = new FingerprintTemplate[gambar.Length];
        Parallel.For(0, gambar.Length, i => templat[i] = Ekstrak(gambar[i].Value, dpi));
        var sama = new List<double>();
        var beda = new List<double>();
        for (var i = 0; i < templat.Length; i++)
        {
            var pencocok = new FingerprintMatcher(templat[i]);
            for (var j = i + 1; j < templat.Length; j++)
            {
                (gambar[i].Key.Identitas == gambar[j].Key.Identitas ? sama : beda).Add(pencocok.Match(templat[j]));
            }
        }
        sama.Sort();
        beda.Sort();
        return new
        {
            dpi,
            sama_jari = new
            {
                pasangan = sama.Count,
                terendah = Bulat(sama.FirstOrDefault()),
                p5 = Bulat(Persentil(sama, 5)),
                median = Bulat(Persentil(sama, 50)),
                di_bawah_40 = sama.Count(s => s < 40),
                di_bawah_50 = sama.Count(s => s < 50),
                di_bawah_60 = sama.Count(s => s < 60),
            },
            beda_jari = new
            {
                pasangan = beda.Count,
                tertinggi = Bulat(beda.LastOrDefault()),
                p99 = Bulat(Persentil(beda, 99)),
                median = Bulat(Persentil(beda, 50)),
                di_atas_40 = beda.Count(s => s >= 40),
                di_atas_50 = beda.Count(s => s >= 50),
                di_atas_60 = beda.Count(s => s >= 60),
            },
            // Positif berarti skor sama-jari terendah masih di atas skor
            // beda-jari tertinggi.
            jarak = sama.Count > 0 && beda.Count > 0 ? Bulat(sama[0] - beda[^1]) : (double?)null,
            waktu_ms = Ms(Stopwatch.GetElapsedTime(mulai)),
        };
    }

    // Waktu pencocokan 1:N untuk beberapa ukuran galeri. Galeri diisi dengan
    // menggandakan templat yang ada: sah untuk waktu, tidak untuk akurasi.
    public Hasil Ukur()
    {
        var galeri = _galeri;
        if (galeri.Entri.IsEmpty)
        {
            return Hasil.Galat(409, "Belum ada templat untuk diukur.");
        }
        var probeTerakhir = _probeTerakhir;
        Sampel? sampel = probeTerakhir?.Sampel;
        if (sampel is null)
        {
            lock (_kunciUbah)
            {
                sampel = _gambar.Values.FirstOrDefault();
            }
        }
        var probe = probeTerakhir is not null && probeTerakhir.Dpi == galeri.Dpi ? probeTerakhir.Templat : galeri.Entri[0].Templat;

        var pencocokan = UkuranGaleriUkur.Select(n =>
        {
            var calon = Enumerable.Range(0, n).Select(i => galeri.Entri[i % galeri.Entri.Length].Templat).ToArray();
            for (var k = 0; k < 3; k++)
            {
                UkurSekali(probe, calon);
            }
            var waktu = Enumerable.Range(0, 30).Select(_ => UkurSekali(probe, calon)).Order().ToArray();
            return new
            {
                templat = n,
                median_ms = Bulat(Persentil(waktu, 50), 2),
                p95_ms = Bulat(Persentil(waktu, 95), 2),
                terendah_ms = Bulat(waktu[0], 2),
                tertinggi_ms = Bulat(waktu[^1], 2),
            };
        }).ToArray();

        object? ekstraksi = null;
        if (sampel is not null)
        {
            Ekstrak(sampel, galeri.Dpi);
            var waktu = Enumerable.Range(0, 20).Select(_ =>
            {
                var mulai = Stopwatch.GetTimestamp();
                Ekstrak(sampel, galeri.Dpi);
                return Stopwatch.GetElapsedTime(mulai).TotalMilliseconds;
            }).Order().ToArray();
            ekstraksi = new
            {
                format = sampel.Format,
                median_ms = Bulat(Persentil(waktu, 50), 2),
                p95_ms = Bulat(Persentil(waktu, 95), 2),
            };
        }

        _log.LogInformation("Pengukuran waktu selesai untuk {Templat} templat nyata.", galeri.Entri.Length);
        return Hasil.Oke(new
        {
            status = "ok",
            dpi = galeri.Dpi,
            templat_nyata = galeri.Entri.Length,
            catatan = "Galeri diisi dengan menggandakan templat yang ada: sah untuk waktu, tidak untuk akurasi.",
            pencocokan,
            ekstraksi,
            mesin = new
            {
                os = RuntimeInformation.OSDescription,
                arsitektur = RuntimeInformation.ProcessArchitecture.ToString(),
                prosesor = Environment.ProcessorCount,
                dotnet = RuntimeInformation.FrameworkDescription,
            },
        });
    }

    public Hasil HapusDataUji()
    {
        lock (_kunciUbah)
        {
            _brankas.TulisTemplat([]);
            _rekamanTertolak = [];
            _galeri = new Galeri(DpiBawaan, []);
            _gambar.Clear();
            _probeTerakhir = null;
            _kembar.Kosongkan();
        }
        _log.LogWarning("Semua data uji dihapus.");
        return Hasil.Oke(new { status = "ok", message = "Semua templat, gambar di memori, dan catatan sampel dihapus." });
    }

    // Rekaman tertolak ikut ditulis kembali apa adanya, supaya tidak hilang
    // diam-diam saat galeri berubah.
    void TulisGaleri(ImmutableArray<Entri> entri) =>
        _brankas.TulisTemplat(entri.Select(e => e.Rekaman).Concat(_rekamanTertolak));

    static FingerprintTemplate Ekstrak(Sampel sampel, int dpi)
    {
        try
        {
            return new FingerprintTemplate(sampel.Gambar(dpi));
        }
        catch (Exception e) when (e is not OutOfMemoryException)
        {
            throw new SampelTidakSahException("Gambar tidak bisa dibaca SourceAFIS (" + e.GetType().Name + ").");
        }
    }

    static double UkurSekali(FingerprintTemplate probe, FingerprintTemplate[] calon)
    {
        var mulai = Stopwatch.GetTimestamp();
        var pencocok = new FingerprintMatcher(probe);
        var terbaik = 0.0;
        foreach (var c in calon)
        {
            terbaik = Math.Max(terbaik, pencocok.Match(c));
        }
        GC.KeepAlive(terbaik);
        return Stopwatch.GetElapsedTime(mulai).TotalMilliseconds;
    }

    static int? DpiDariVersi(string versi) =>
        versi.StartsWith(AwalanVersi, StringComparison.Ordinal)
        && int.TryParse(versi.AsSpan(AwalanVersi.Length), out var dpi) && DpiKalibrasi.Contains(dpi)
            ? dpi
            : null;

    static string VersiPustaka()
    {
        var v = typeof(FingerprintTemplate).Assembly.GetName().Version;
        return v is null ? "0.0.0" : $"{v.Major}.{v.Minor}.{v.Build}";
    }

    static object InfoSampel(Sampel s) => new { format = s.Format, lebar = s.Lebar, tinggi = s.Tinggi, dpi_alat = s.DpiAlat };

    static double Persentil(IReadOnlyList<double> terurut, double p) =>
        terurut.Count == 0 ? 0 : terurut[(int)Math.Clamp(Math.Ceiling(p / 100 * terurut.Count) - 1, 0, terurut.Count - 1)];

    static double Bulat(double nilai) => Bulat(nilai, 1);

    static double Bulat(double nilai, int digit) => Math.Round(nilai, digit, MidpointRounding.AwayFromZero);

    static double Ms(TimeSpan waktu) => Bulat(waktu.TotalMilliseconds, 1);
}

// Galeri tidak pernah diubah di tempat. Perubahan membuat galeri baru lalu
// menukarnya, jadi identifikasi yang sedang berjalan tetap membaca galeri utuh.
sealed record Galeri(int Dpi, ImmutableArray<Entri> Entri);

sealed record Entri(string Identitas, string Jari, int Urutan, FingerprintTemplate Templat, RekamanTemplat Rekaman);

sealed record Probe(Sampel Sampel, FingerprintTemplate Templat, int Dpi);

// Sampel dari halaman yang sudah dibaca. Isi berisi piksel 8-bit baris demi
// baris untuk format raw, atau berkas PNG untuk format png.
sealed class Sampel
{
    static readonly byte[] TandaPng = [0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A];
    const int SisiTerkecil = 50;
    const int SisiTerbesar = 2000;

    public required string Format { get; init; }
    public required int Lebar { get; init; }
    public required int Tinggi { get; init; }
    public int? DpiAlat { get; init; }
    public required byte[] Isi { get; init; }

    // SHA-256 isi, untuk penjaga sampel kembar.
    public required string Sidik { get; init; }

    public FingerprintImage Gambar(int dpi)
    {
        var opsi = new FingerprintImageOptions { Dpi = dpi };
        return Format == "raw" ? new FingerprintImage(Lebar, Tinggi, Isi, opsi) : new FingerprintImage(Isi, opsi);
    }

    // format: "raw" untuk SampleFormat.Raw, "png" untuk SampleFormat.PngImage.
    // teks: isi yang diterima halaman dari WebSDK, apa adanya.
    public static Sampel Baca(string? format, string? teks)
    {
        if (string.IsNullOrEmpty(teks))
        {
            throw new SampelTidakSahException("Sampel kosong.");
        }
        try
        {
            return format switch
            {
                "raw" => BacaRaw(teks, bungkus: true),
                "png" => BacaPng(teks),
                _ => throw new SampelTidakSahException("Format sampel harus raw atau png."),
            };
        }
        catch (Exception e) when (e is FormatException or JsonException or InvalidOperationException)
        {
            throw new SampelTidakSahException("Sampel " + format + " tidak bisa dibaca (" + e.GetType().Name + ").");
        }
    }

    // Raw dari WebSDK: Data sebuah BioSample, base64url dari JSON
    // {Data: piksel base64url, Format: {iWidth, iHeight, iXdpi, ...}}.
    // Struktur ini tidak didokumentasikan HID. Kalau berbeda, pesan galatnya
    // menyebut kolom yang ditemukan, tanpa isi sampelnya.
    //
    // Pikselnya boleh diikuti beberapa byte yang bukan gambar: U.are.U 4500
    // mengirim 500 × 550 piksel ditambah 12 byte di akhir (terukur dengan
    // pembaca sekolah, 1 Oktober 2026; pada uji 3 Oktober kedua belas byte
    // itu selalu nol). Yang dipakai hanya lebar × tinggi byte pertama, juga
    // untuk Sidik, supaya sampel yang sama dengan ekor berbeda tetap dikenali
    // kembar.
    static Sampel BacaRaw(string teks, bool bungkus)
    {
        using var dokumen = JsonDocument.Parse(BacaBase64(teks));
        var akar = dokumen.RootElement;
        if (akar.ValueKind != JsonValueKind.Object)
        {
            throw new SampelTidakSahException("Sampel raw bukan objek JSON.");
        }
        // BioSample utuh {Header, Data, Version}: isinya ada di Data.
        if (bungkus && akar.TryGetProperty("Header", out _) && !akar.TryGetProperty("Format", out _)
            && akar.TryGetProperty("Data", out var dalam) && dalam.ValueKind == JsonValueKind.String)
        {
            return BacaRaw(dalam.GetString()!, bungkus: false);
        }
        if (!akar.TryGetProperty("Data", out var data) || data.ValueKind != JsonValueKind.String
            || !akar.TryGetProperty("Format", out var format) || format.ValueKind != JsonValueKind.Object)
        {
            throw new SampelTidakSahException("Struktur sampel raw tidak dikenal. Kolom yang ada: "
                                              + string.Join(", ", akar.EnumerateObject().Select(p => p.Name)) + ".");
        }
        var piksel = BacaBase64(data.GetString()!);
        var lebar = Angka(format, "iWidth");
        var tinggi = Angka(format, "iHeight");
        var dpi = Angka(format, "iXdpi");
        var ringkasFormat = format.GetRawText();
        if (ringkasFormat.Length > 300)
        {
            ringkasFormat = ringkasFormat[..300] + "…";
        }
        if (lebar is null or < SisiTerkecil or > SisiTerbesar || tinggi is null or < SisiTerkecil or > SisiTerbesar)
        {
            throw new SampelTidakSahException("Ukuran gambar raw tidak sah. Format: " + ringkasFormat);
        }
        // Kelebihannya harus lebih kecil dari sisi terpendek. Lebar atau tinggi
        // yang meleset satu saja mengubah panjangnya sebesar sisi yang lain,
        // jadi Format yang tidak menggambarkan datanya tetap ditolak, bukan
        // dibaca sebagai gambar yang miring.
        var luas = lebar.Value * tinggi.Value;
        var lebih = piksel.Length - luas;
        if (lebih < 0 || lebih >= Math.Min(lebar.Value, tinggi.Value))
        {
            throw new SampelTidakSahException($"Panjang piksel {piksel.Length} tidak cocok dengan {lebar} × {tinggi}. Format: {ringkasFormat}");
        }
        var gambar = lebih == 0 ? piksel : piksel[..luas];
        return new Sampel
        {
            Format = "raw",
            Lebar = lebar.Value,
            Tinggi = tinggi.Value,
            DpiAlat = dpi is >= 100 and <= 2000 ? dpi : null,
            Isi = gambar,
            Sidik = Convert.ToHexStringLower(SHA256.HashData(gambar)),
        };
    }

    // PNG dari WebSDK: base64url berkas PNG. Ukurannya dibaca dari chunk IHDR
    // tanpa mendekode gambarnya; dekode baru terjadi di SourceAFIS.
    static Sampel BacaPng(string teks)
    {
        var png = BacaBase64(teks);
        if (png.Length < 24 || !png.AsSpan(0, 8).SequenceEqual(TandaPng) || !png.AsSpan(12, 4).SequenceEqual("IHDR"u8))
        {
            throw new SampelTidakSahException("Sampel png bukan berkas PNG.");
        }
        var lebar = BinaryPrimitives.ReadInt32BigEndian(png.AsSpan(16, 4));
        var tinggi = BinaryPrimitives.ReadInt32BigEndian(png.AsSpan(20, 4));
        if (lebar is < SisiTerkecil or > SisiTerbesar || tinggi is < SisiTerkecil or > SisiTerbesar)
        {
            throw new SampelTidakSahException($"Ukuran gambar png tidak sah: {lebar} × {tinggi}.");
        }
        return new Sampel
        {
            Format = "png",
            Lebar = lebar,
            Tinggi = tinggi,
            DpiAlat = null,
            Isi = png,
            Sidik = Convert.ToHexStringLower(SHA256.HashData(png)),
        };
    }

    // Base64 biasa maupun base64url (dipakai WebSDK), dengan atau tanpa
    // padding, dan dengan atau tanpa awalan data URI.
    static byte[] BacaBase64(string teks)
    {
        var koma = teks.StartsWith("data:", StringComparison.Ordinal) ? teks.IndexOf(',') : -1;
        var isi = (koma >= 0 ? teks[(koma + 1)..] : teks).Trim().Replace('-', '+').Replace('_', '/');
        switch (isi.Length % 4)
        {
            case 2: isi += "=="; break;
            case 3: isi += "="; break;
            case 1: throw new FormatException("Panjang base64 tidak sah.");
        }
        return Convert.FromBase64String(isi);
    }

    static int? Angka(JsonElement objek, string nama) =>
        objek.TryGetProperty(nama, out var nilai) && nilai.ValueKind == JsonValueKind.Number && nilai.TryGetInt32(out var angka)
            ? angka
            : null;
}

sealed class SampelTidakSahException(string pesan) : Exception(pesan);

// Sidik sampel yang pernah dipakai, dibatasi jumlahnya (yang tertua dibuang).
sealed class PenjagaKembar(int batas)
{
    readonly Lock _kunci = new();
    readonly HashSet<string> _sidik = new(StringComparer.Ordinal);
    readonly Queue<string> _urutan = new();

    public bool Terlihat(string sidik)
    {
        lock (_kunci)
        {
            return _sidik.Contains(sidik);
        }
    }

    // Benar kalau sidik ini baru; sekaligus dicatat.
    public bool Tandai(string sidik)
    {
        lock (_kunci)
        {
            if (!_sidik.Add(sidik))
            {
                return false;
            }
            _urutan.Enqueue(sidik);
            if (_urutan.Count > batas)
            {
                _sidik.Remove(_urutan.Dequeue());
            }
            return true;
        }
    }

    public void Kosongkan()
    {
        lock (_kunci)
        {
            _sidik.Clear();
            _urutan.Clear();
        }
    }
}

// Jawaban untuk halaman: kode HTTP dan isi JSON-nya.
sealed record Hasil(int Kode, object Isi)
{
    public static Hasil Oke(object isi) => new(200, isi);

    public static Hasil Galat(int kode, string pesan, object? rincian = null) =>
        new(kode, rincian is null
            ? new { status = "error", message = pesan }
            : (object)new { status = "error", message = pesan, rincian });
}

sealed record PermintaanIdentifikasi(string? Format, string? Sampel, string? Tantangan);

sealed record PermintaanDaftar(string? Identitas, string? Jari, string? Format, string[]? Sampel);

sealed record PermintaanKalibrasi(int? Terapkan);
