// Pencocok.cs — membaca sampel, mengekstrak templat, dan mencocokkan 1:N.
//
// Halaman mengirim sampel dari HID Authentication Device Client apa adanya.
// Berkas ini membacanya, mengekstrak templat dengan SourceAFIS, lalu
// mencocokkannya dengan semua templat di galeri. Hasil yang diterima
// ditandatangani Brankas sebagai "absen".
//
// Aturan yang dipegang:
// - Galeri hanya berisi templat, dimuat dari templat.json yang terenkripsi.
//   Gambar sidik jari tidak pernah ditulis ke disk. Gambar pendaftaran uji
//   disimpan di memori selama jembatan hidup, untuk kalibrasi DPI. Gambar
//   pendaftaran siswa dan guru tidak disimpan sama sekali: begitu templatnya
//   diekstrak, gambarnya dilepas.
// - SourceAFIS tidak tahan beda skala, jadi probe dan galeri harus diekstrak
//   dengan DPI yang sama. DPI itu ditulis di kolom versi setiap rekaman,
//   bersama versi pustaka, karena keduanya menentukan apakah dua templat bisa
//   dibandingkan. Contoh: sourceafis-net-3.14.0-508.
// - DPI galeri mengikuti alat: pendaftaran pertama di galeri kosong memakai
//   DPI yang dilaporkan sampelnya. DPI itu bergantung driver, bukan pembaca.
// - Identifikasi diterima kalau skor terbaik mencapai Ambang DAN cukup jauh di
//   atas identitas kedua. Ambang 40 yang dianjurkan untuk 1:1 terlalu longgar
//   untuk ratusan templat: setiap perbandingan membawa peluang salah-cocok.
// - Sampel yang sama persis dengan sampel sebelumnya ditolak. Tempelan jari
//   sungguhan tidak pernah menghasilkan byte yang sama, jadi sampel kembar
//   hampir pasti rekaman yang diputar ulang.
// - Rekaman templat.json yang gagal dibuka tidak dibuang diam-diam: ia
//   dihitung, dilaporkan di /status, dan tetap ditulis kembali apa adanya.
//
// - Siswa dan guru hanya bisa didaftarkan dan dicabut lewat sesi berizin:
//   jembatan menerbitkan tantangan, dan server menandatanganinya untuk satu
//   orang dan satu jari (lihat Brankas.cs). Empat tempelan harus lolos
//   gerbang mutu, lalu satu tempelan uji harus dikenali sebagai orang itu.
//   Nilai mutu dari alat tidak dipakai: pada uji 5 Oktober 2026 nilainya
//   "Good" untuk semua tempelan, juga yang kemudian tidak dikenali.
//
// Pendaftaran tanpa izin server, kalibrasi, ukur waktu, dan hapus data uji
// hanya ada di jendela konsol, dan hanya untuk identitas uji:.

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

    // Gerbang mutu pendaftaran: keserasian terendah keempat tempelan. Dihitung
    // mundur dari uji 5 Oktober 2026 (36 jari, enam orang dewasa): jari yang
    // lolos angka ini dikenali 92,6% pada tempelan pertama dan 100% dalam
    // tiga, sedangkan aturan lama (40) memberi 82,8% dan 93,9%.
    public const double GerbangMutu = 80;

    // Batas satu sesi pendaftaran berizin.
    public const int MaksTempelan = 10;
    public const int MaksUji = 3;
    public const int UmurSesiDetik = 900;
    // Sesi terbuka paling banyak, dihitung per golongan: pendaftaran yang
    // belum diizinkan, pendaftaran yang sudah diizinkan, dan pencabutan.
    const int MaksSesi = 4;
    // DPI galeri sebelum ada pendaftaran, dan DPI untuk sampel yang tidak
    // membawa DPI (PNG). Pendaftaran pertama di galeri kosong menggantinya
    // dengan DPI yang dilaporkan sampelnya; galeri yang sudah berisi tetap
    // memakai DPI rekamannya.
    //
    // DPI bergantung driver, bukan pembaca. U.are.U 4500 yang sama melapor 700
    // lewat driver DigitalPersona (500 × 550) dan 508 lewat driver WBF (320 ×
    // 360). Kalibrasi 3 Oktober 2026 di kedua komputer membenarkan angka itu:
    // dengan driver DigitalPersona 700 unggul (jarak 42,2); dengan driver WBF
    // di PC kiosk 500 dan 512 unggul (109,7 dan 93,9), sedangkan 700 hanya
    // 8,2. Jadi angka mati 700 dari versi 0.1.2 salah untuk PC kiosk.
    const int DpiBawaan = 500;
    const int DpiTerkecil = 250;
    const int DpiTerbesar = 1200;
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

    // Sesi pendaftaran dan pencabutan berizin. Urutan kunci: _kunciSesi dulu,
    // baru _kunciUbah; tidak pernah sebaliknya.
    readonly Lock _kunciSesi = new();
    readonly Dictionary<string, SesiDaftar> _sesiDaftar = new(StringComparer.Ordinal);
    readonly Dictionary<string, SesiCabut> _sesiCabut = new(StringComparer.Ordinal);

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

        // Diagnostik prototipe: kemiripan dengan tempelan sebelumnya, untuk
        // melihat apakah tempelan berturut-turut memang jari yang sama.
        var sebelumnya = _probeTerakhir;
        double? skorSebelumnya = sebelumnya is not null && sebelumnya.Dpi == galeri.Dpi
            ? Bulat(pencocok.Match(sebelumnya.Templat))
            : null;

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
            skor_probe_sebelumnya = skorSebelumnya,
            waktu_ms = new { baca = Ms(waktuBaca), ekstraksi = Ms(waktuEkstraksi), cocok = Ms(waktuCocok), total = Ms(total) },
            pesan = tanda?.Pesan,
            tanda_tangan = tanda?.Hmac,
        });
    }

    // Pendaftaran uji, hanya dari halaman uji di jendela konsol dan hanya
    // untuk identitas uji:. Tepat empat tempelan satu jari dalam satu kiriman.
    // Gerbang mutunya sama dengan pendaftaran berizin, supaya angka halaman
    // uji mencerminkan aturan yang dipakai untuk siswa. Jari itu tidak boleh
    // mirip jari yang sudah terdaftar atas nama identitas lain. Mendaftarkan
    // ulang identitas dan jari yang sama menggantikan yang lama.
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
        if (!Brankas.IdentitasUji(identitas))
        {
            return Hasil.Galat(400, "Halaman uji hanya mendaftarkan identitas uji. Siswa dan guru didaftarkan dari panel admin.");
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
            // Galeri kosong mengikuti DPI yang dilaporkan alat. Galeri yang
            // sudah berisi tetap memakai DPI-nya, supaya templat lama dan
            // baru sebanding.
            var dpi = galeri.Entri.IsEmpty ? DpiSampel(sampel) ?? galeri.Dpi : galeri.Dpi;
            var templat = new FingerprintTemplate[JumlahTempelan];
            for (var i = 0; i < JumlahTempelan; i++)
            {
                try
                {
                    templat[i] = Ekstrak(sampel[i], dpi);
                }
                catch (SampelTidakSahException e)
                {
                    return Hasil.Galat(400, $"Tempelan ke-{i + 1}: {e.Message}");
                }
            }

            var (keserasian, terhubung) = NilaiKeserasian(templat);
            if (keserasian.Min() < GerbangMutu || !terhubung)
            {
                var lemah = Enumerable.Range(0, JumlahTempelan).Where(i => keserasian[i] < GerbangMutu).Select(i => i + 1).ToArray();
                if (lemah.Length == 0)
                {
                    lemah = [TempelanTerlemah(keserasian) + 1];
                }
                return Hasil.Galat(422, $"Tempelan ke-{string.Join(", ", lemah)} tidak cukup serasi dengan tempelan lain. Ulangi tempelan itu.",
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

            var versi = AwalanVersi + dpi;
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
            _galeri = new Galeri(dpi, entriBaru);
            if (dpi != galeri.Dpi)
            {
                _probeTerakhir = null;
                _log.LogWarning("DPI galeri mengikuti alat: {Dpi} (sebelumnya {Lama}).", dpi, galeri.Dpi);
            }
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
                dpi,
                sampel = InfoSampel(sampel[0]),
                waktu_ms = Ms(Stopwatch.GetElapsedTime(mulai)),
            });
        }
    }

    // ---------- Pendaftaran dan pencabutan berizin ----------
    //
    // Satu sesi untuk satu jari milik satu orang:
    //   mulai    jembatan menerbitkan sesi: tantangan sekali pakai;
    //   izin     halaman membawa tanda tangan server atas sesi itu;
    //   tempel   empat tempelan, dinilai begitu lengkap. Selama belum lolos
    //            gerbang mutu, tempelan terlemah dibuang dan diminta ulang.
    //            Sesudah lolos, satu tempelan uji harus dikenali sebagai jari
    //            yang baru didaftarkan;
    //   selesai  templat disimpan, dan tanda terimanya ditandatangani untuk
    //            server.
    // Tidak ada yang disimpan sebelum "selesai". Sesi hanya memegang templat
    // tempelannya, bukan gambarnya.

    public Hasil MulaiDaftar(PermintaanMulaiDaftar permintaan)
    {
        if (!Brankas.IdentitasResmi(permintaan.Identitas)
            || !Brankas.MetadataSah(permintaan.Identitas, permintaan.Jari, 1, AwalanVersi + DpiBawaan))
        {
            return Hasil.Galat(400, "Identitas atau jari tidak sah.");
        }
        var sesi = Convert.ToHexStringLower(RandomNumberGenerator.GetBytes(32));
        lock (_kunciSesi)
        {
            // Sesi baru hanya menggusur sesi lain yang juga belum diizinkan.
            // Rute ini bisa dipanggil tanpa izin, jadi ia tidak boleh bisa
            // membuang pendaftaran yang sedang berjalan.
            BuangSesiLama(_sesiDaftar, s => s.Dibuat, s => s.Tahap == Tahap.MenungguIzin);
            _sesiDaftar[sesi] = new SesiDaftar(permintaan.Identitas!, permintaan.Jari!);
        }
        return Hasil.Oke(new { status = "ok", sesi, berlaku_detik = UmurSesiDetik });
    }

    public Hasil IzinkanDaftar(PermintaanIzin permintaan)
    {
        lock (_kunciSesi)
        {
            var sesi = CariSesiDaftar(permintaan.Sesi);
            if (sesi is null)
            {
                return SesiTidakAda();
            }
            if (sesi.Tahap != Tahap.MenungguIzin)
            {
                return Hasil.Galat(409, "Sesi ini sudah diizinkan.");
            }
            if (!_brankas.IzinDaftarSah(permintaan.Sesi!, sesi.Identitas, sesi.Jari, permintaan.Izin))
            {
                // Sesinya dibuang, supaya satu tantangan tidak bisa dicoba
                // dengan izin yang berbeda-beda.
                _sesiDaftar.Remove(permintaan.Sesi!);
                _log.LogWarning("Izin pendaftaran {Identitas}/{Jari} tidak cocok.", sesi.Identitas, sesi.Jari);
                return Hasil.Galat(403, "Izin dari server tidak cocok. Mulai lagi.");
            }
            // Sesi yang sudah diizinkan dibatasi tersendiri, dan hanya izin
            // yang sah yang bisa menggusur salah satunya.
            BuangSesiLama(_sesiDaftar, s => s.Dibuat, s => s.Tahap != Tahap.MenungguIzin);
            sesi.Tahap = Tahap.Menangkap;
            return Hasil.Oke(new { status = "ok", tahap = "tempel", diterima = 0, butuh = JumlahTempelan });
        }
    }

    // Satu tempelan untuk sesi yang sudah diizinkan. pngBoleh hanya benar
    // dalam mode pengembangan; di PC kiosk hanya sampel raw yang diterima.
    public Hasil Tempel(PermintaanTempel permintaan, bool pngBoleh)
    {
        lock (_kunciSesi)
        {
            var sesi = CariSesiDaftar(permintaan.Sesi);
            if (sesi is null)
            {
                return SesiTidakAda();
            }
            if (sesi.Tahap == Tahap.MenungguIzin)
            {
                return Hasil.Galat(409, "Sesi ini belum diizinkan server.");
            }
            if (sesi.Tahap == Tahap.Lulus)
            {
                return Hasil.Galat(409, "Sesi ini sudah lulus. Selesaikan pendaftarannya.");
            }
            if (!pngBoleh && !string.Equals(permintaan.Format, "raw", StringComparison.Ordinal))
            {
                return Hasil.Galat(400, "Pendaftaran hanya menerima sampel raw.");
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
            if (!_kembar.Tandai(sampel.Sidik))
            {
                return Hasil.Galat(409, "Sampel ini sama persis dengan sampel sebelumnya. Tempelkan jari lagi.");
            }
            var galeri = _galeri;
            if (sesi.Tempelan == 0)
            {
                // Sama dengan pendaftaran uji: galeri kosong mengikuti DPI
                // yang dilaporkan alat, galeri berisi memakai DPI-nya.
                sesi.Dpi = galeri.Entri.IsEmpty && sampel.DpiAlat is int dpiAlat && DpiMasukAkal(dpiAlat) ? dpiAlat : galeri.Dpi;
            }
            FingerprintTemplate templat;
            try
            {
                templat = Ekstrak(sampel, sesi.Dpi);
            }
            catch (SampelTidakSahException e)
            {
                return Hasil.Galat(400, e.Message);
            }
            return sesi.Tahap == Tahap.Menangkap
                ? TempelDaftar(permintaan.Sesi!, sesi, templat, galeri)
                : TempelUji(permintaan.Sesi!, sesi, templat, galeri);
        }
    }

    Hasil TempelDaftar(string id, SesiDaftar sesi, FingerprintTemplate templat, Galeri galeri)
    {
        sesi.Tempelan++;
        sesi.Calon.Add(templat);
        if (sesi.Calon.Count < JumlahTempelan)
        {
            return Hasil.Oke(new { status = "ok", tahap = "tempel", diterima = sesi.Calon.Count, butuh = JumlahTempelan, tempelan = sesi.Tempelan });
        }

        var (keserasian, terhubung) = NilaiKeserasian(sesi.Calon);
        var skor = keserasian.Select(s => Bulat(s)).ToArray();
        if (keserasian.Min() < GerbangMutu || !terhubung)
        {
            if (sesi.Tempelan >= MaksTempelan)
            {
                _sesiDaftar.Remove(id);
                _log.LogWarning("Pendaftaran {Identitas}/{Jari} gagal: keserasian {Keserasian} setelah {Tempelan} tempelan.",
                                    sesi.Identitas, sesi.Jari, string.Join(" ", skor), sesi.Tempelan);
                return Hasil.Galat(422, $"Jari ini tidak cukup serasi setelah {MaksTempelan} tempelan. Coba jari lain.",
                                   new { tahap = "gagal", skor_keserasian = skor });
            }
            var buang = TempelanTerlemah(keserasian);
            sesi.Calon.RemoveAt(buang);
            return Hasil.Oke(new
            {
                status = "ok",
                tahap = "tempel",
                diterima = sesi.Calon.Count,
                butuh = JumlahTempelan,
                tempelan = sesi.Tempelan,
                dibuang = buang + 1,
                skor_keserasian = skor,
                alasan = "Satu tempelan belum serasi dengan yang lain. Tempelkan jari yang sama sekali lagi, dengan cara yang sama.",
            });
        }

        var mirip = JariLainTermirip(sesi, galeri);
        if (mirip is not null)
        {
            _sesiDaftar.Remove(id);
            return JariSudahTerdaftar(sesi, mirip);
        }
        sesi.Keserasian = keserasian;
        sesi.Tahap = Tahap.MenungguUji;
        return Hasil.Oke(new { status = "ok", tahap = "uji", skor_keserasian = skor, mutu = MutuDari(keserasian), tempelan = sesi.Tempelan });
    }

    // Tempelan uji: aturannya sama dengan identifikasi di kiosk, tetapi yang
    // dihitung hanya templat yang baru. Jari lain milik orang yang sama tidak
    // boleh menolong, karena yang diuji adalah jari ini.
    Hasil TempelUji(string id, SesiDaftar sesi, FingerprintTemplate templat, Galeri galeri)
    {
        sesi.Uji++;
        var pencocok = new FingerprintMatcher(templat);
        var skorBaru = sesi.Calon.Max(c => pencocok.Match(c));
        var skorLain = 0.0;
        foreach (var e in galeri.Entri.Where(e => e.Identitas != sesi.Identitas))
        {
            skorLain = Math.Max(skorLain, pencocok.Match(e.Templat));
        }
        if (skorBaru >= Ambang && skorBaru - skorLain >= Selisih)
        {
            sesi.Tahap = Tahap.Lulus;
            return Hasil.Oke(new { status = "ok", tahap = "siap", dikenali = true, skor_uji = Bulat(skorBaru), skor_orang_lain = Bulat(skorLain) });
        }
        if (sesi.Uji >= MaksUji)
        {
            _sesiDaftar.Remove(id);
            _log.LogWarning("Pendaftaran {Identitas}/{Jari} gagal: tempelan uji tidak dikenali (skor {Skor:F1}).", sesi.Identitas, sesi.Jari, skorBaru);
            return Hasil.Galat(422, "Tempelan uji tidak dikenali sebagai jari yang baru didaftarkan. Ulangi pendaftaran jari ini.",
                               new { tahap = "gagal", skor_uji = Bulat(skorBaru), skor_orang_lain = Bulat(skorLain) });
        }
        return Hasil.Oke(new
        {
            status = "ok",
            tahap = "uji",
            dikenali = false,
            skor_uji = Bulat(skorBaru),
            skor_orang_lain = Bulat(skorLain),
            sisa = MaksUji - sesi.Uji,
        });
    }

    // Menyimpan templat sesi yang sudah lulus, lalu menandatangani tanda
    // terimanya dengan tantangan dari server.
    public Hasil SelesaiDaftar(PermintaanSelesai permintaan)
    {
        if (!Brankas.TantanganSah(permintaan.Tantangan))
        {
            return Hasil.Galat(400, "Tantangan tidak sah.");
        }
        lock (_kunciSesi)
        {
            var sesi = CariSesiDaftar(permintaan.Sesi);
            if (sesi is null)
            {
                return SesiTidakAda();
            }
            if (sesi.Tahap != Tahap.Lulus)
            {
                return Hasil.Galat(409, "Pendaftaran ini belum lulus tempelan uji.");
            }
            _sesiDaftar.Remove(permintaan.Sesi!);
            lock (_kunciUbah)
            {
                var galeri = _galeri;
                if (!galeri.Entri.IsEmpty && galeri.Dpi != sesi.Dpi)
                {
                    return Hasil.Galat(409, "DPI galeri berubah selagi pendaftaran berjalan. Ulangi pendaftaran jari ini.");
                }
                // Jari lain bisa saja terdaftar selagi sesi ini berjalan.
                var mirip = JariLainTermirip(sesi, galeri);
                if (mirip is not null)
                {
                    return JariSudahTerdaftar(sesi, mirip);
                }
                var versi = AwalanVersi + sesi.Dpi;
                var rekaman = sesi.Calon.Select((t, i) => _brankas.Enkripsi(
                    new Templat(sesi.Identitas, sesi.Jari, i + 1, versi, t.ToByteArray()))).ToArray();
                var diganti = galeri.Entri.Any(x => x.Identitas == sesi.Identitas && x.Jari == sesi.Jari);
                var entriBaru = galeri.Entri
                    .Where(x => !(x.Identitas == sesi.Identitas && x.Jari == sesi.Jari))
                    .Concat(rekaman.Select((r, i) => new Entri(sesi.Identitas, sesi.Jari, i + 1, sesi.Calon[i], r)))
                    .ToImmutableArray();
                TulisGaleri(entriBaru);
                _galeri = new Galeri(sesi.Dpi, entriBaru);
                if (sesi.Dpi != galeri.Dpi)
                {
                    _probeTerakhir = null;
                    _log.LogWarning("DPI galeri mengikuti alat: {Dpi} (sebelumnya {Lama}).", sesi.Dpi, galeri.Dpi);
                }
                var mutu = MutuDari(sesi.Keserasian);
                var tanda = _brankas.TandatanganiTerdaftar(permintaan.Tantangan!, sesi.Identitas, sesi.Jari, mutu);
                _log.LogInformation("Terdaftar {Identitas}/{Jari}: mutu {Mutu}, {Tempelan} tempelan.", sesi.Identitas, sesi.Jari, mutu, sesi.Tempelan);
                return Hasil.Oke(new
                {
                    status = "ok",
                    perangkat = _brankas.Perangkat,
                    identitas = sesi.Identitas,
                    jari = sesi.Jari,
                    tempelan = JumlahTempelan,
                    mutu,
                    diganti,
                    dpi = sesi.Dpi,
                    pesan = tanda.Pesan,
                    tanda_tangan = tanda.Hmac,
                });
            }
        }
    }

    public Hasil MulaiCabut(PermintaanMulaiCabut permintaan)
    {
        if (!Brankas.IdentitasResmi(permintaan.Identitas))
        {
            return Hasil.Galat(400, "Identitas tidak sah.");
        }
        var sesi = Convert.ToHexStringLower(RandomNumberGenerator.GetBytes(32));
        lock (_kunciSesi)
        {
            BuangSesiLama(_sesiCabut, s => s.Dibuat);
            _sesiCabut[sesi] = new SesiCabut(permintaan.Identitas!, Environment.TickCount64);
        }
        return Hasil.Oke(new { status = "ok", sesi, berlaku_detik = UmurSesiDetik });
    }

    // Menghapus semua templat satu orang, lalu menandatangani tanda terimanya
    // dengan tantangan dari server. Orang yang memang tidak punya templat
    // dijawab dengan jumlah 0, supaya catatan server tetap bisa dibereskan.
    public Hasil Cabut(PermintaanCabut permintaan)
    {
        if (!Brankas.TantanganSah(permintaan.Tantangan))
        {
            return Hasil.Galat(400, "Tantangan tidak sah.");
        }
        lock (_kunciSesi)
        {
            // Sesi cabut sekali pakai: diambil dari daftar sebelum izinnya
            // diperiksa.
            if (!Brankas.TantanganSah(permintaan.Sesi) || !_sesiCabut.Remove(permintaan.Sesi!, out var sesi)
                || Kedaluwarsa(sesi.Dibuat))
            {
                return SesiTidakAda();
            }
            if (!_brankas.IzinCabutSah(permintaan.Sesi!, sesi.Identitas, permintaan.Izin))
            {
                _log.LogWarning("Izin pencabutan {Identitas} tidak cocok.", sesi.Identitas);
                return Hasil.Galat(403, "Izin dari server tidak cocok. Mulai lagi.");
            }
            // Pendaftaran orang itu yang masih berjalan ikut batal. Tanpa
            // ini, sesi yang diizinkan sebelum pencabutan masih bisa
            // menyimpan templatnya lagi sesudahnya.
            foreach (var id in _sesiDaftar.Where(kv => kv.Value.Identitas == sesi.Identitas).Select(kv => kv.Key).ToArray())
            {
                _sesiDaftar.Remove(id);
            }
            lock (_kunciUbah)
            {
                var galeri = _galeri;
                var sisa = galeri.Entri.Where(e => e.Identitas != sesi.Identitas).ToImmutableArray();
                var tertolak = _rekamanTertolak.Where(r => r.Identitas != sesi.Identitas).ToImmutableArray();
                var jumlah = galeri.Entri.Length - sisa.Length + _rekamanTertolak.Length - tertolak.Length;
                if (jumlah > 0)
                {
                    // Memori baru diganti setelah penulisan berhasil. Kalau
                    // penulisan gagal, pencabutan yang diulang masih melihat
                    // rekaman yang sama dan menghapusnya.
                    TulisGaleri(sisa, tertolak);
                    _rekamanTertolak = tertolak;
                    _galeri = new Galeri(galeri.Dpi, sisa);
                }
                // Tidak ada gambar yang perlu dibuang: _gambar hanya memuat
                // identitas uji:.
                var tanda = _brankas.TandatanganiDicabut(permintaan.Tantangan!, sesi.Identitas, Math.Min(jumlah, 99));
                _log.LogWarning("Dicabut {Identitas}: {Jumlah} templat dihapus.", sesi.Identitas, jumlah);
                return Hasil.Oke(new
                {
                    status = "ok",
                    perangkat = _brankas.Perangkat,
                    identitas = sesi.Identitas,
                    jumlah = Math.Min(jumlah, 99),
                    pesan = tanda.Pesan,
                    tanda_tangan = tanda.Hmac,
                });
            }
        }
    }

    // Keserasian tempelan-tempelan satu jari.
    //   Keserasian[i]  skor terbaik tempelan i terhadap tempelan lain;
    //   Terhubung      semua tempelan saling terjangkau lewat pasangan yang
    //                  cocok 1:1. Empat tempelan yang hanya cocok berpasangan
    //                  dua-dua tidak terhubung: jarinya diletakkan dengan dua
    //                  cara yang tidak saling mengenali.
    static (double[] Keserasian, bool Terhubung) NilaiKeserasian(IReadOnlyList<FingerprintTemplate> templat)
    {
        var n = templat.Count;
        var skor = new double[n, n];
        var keserasian = new double[n];
        for (var i = 0; i < n; i++)
        {
            var pencocok = new FingerprintMatcher(templat[i]);
            for (var j = 0; j < n; j++)
            {
                if (j != i)
                {
                    skor[i, j] = pencocok.Match(templat[j]);
                    keserasian[i] = Math.Max(keserasian[i], skor[i, j]);
                }
            }
        }
        var terjangkau = new bool[n];
        var antre = new Queue<int>();
        terjangkau[0] = true;
        antre.Enqueue(0);
        while (antre.Count > 0)
        {
            var i = antre.Dequeue();
            for (var j = 0; j < n; j++)
            {
                if (!terjangkau[j] && Math.Max(skor[i, j], skor[j, i]) >= AmbangSatuLawanSatu)
                {
                    terjangkau[j] = true;
                    antre.Enqueue(j);
                }
            }
        }
        return (keserasian, terjangkau.All(t => t));
    }

    // Tempelan yang dibuang kalau gerbang belum lolos: yang keserasiannya
    // terendah; kalau sama, yang paling lama.
    static int TempelanTerlemah(double[] keserasian) => Array.IndexOf(keserasian, keserasian.Min());

    static int MutuDari(double[] keserasian) => (int)Math.Clamp(Math.Floor(keserasian.Min()), 0, 9999);

    // Jari terdaftar yang paling mirip dengan calon sesi ini, kalau skornya
    // mencapai ambang 1:1: jari orang lain, atau jari lain orang yang sama.
    // Jari yang sedang didaftarkan ulang tidak dihitung.
    (string Identitas, string Jari, double Skor)? JariLainTermirip(SesiDaftar sesi, Galeri galeri)
    {
        (string Identitas, string Jari, double Skor)? terbaik = null;
        foreach (var calon in sesi.Calon)
        {
            var pencocok = new FingerprintMatcher(calon);
            foreach (var e in galeri.Entri.Where(x => !(x.Identitas == sesi.Identitas && x.Jari == sesi.Jari)))
            {
                var s = pencocok.Match(e.Templat);
                if (s >= AmbangSatuLawanSatu && (terbaik is null || s > terbaik.Value.Skor))
                {
                    terbaik = (e.Identitas, e.Jari, s);
                }
            }
        }
        return terbaik;
    }

    // Penolakan ini dicatat sebagai peringatan, supaya sampai ke Event Viewer
    // dan ambang 1:1 bisa ditinjau dari pendaftaran sungguhan: dua jari
    // berbeda sesekali melewati ambang itu.
    Hasil JariSudahTerdaftar(SesiDaftar sesi, (string Identitas, string Jari, double Skor)? mirip)
    {
        var m = mirip!.Value;
        _log.LogWarning("Pendaftaran {Identitas}/{Jari} ditolak: mirip {IdentitasLain}/{JariLain}, skor {Skor:F1}.",
                            sesi.Identitas, sesi.Jari, m.Identitas, m.Jari, m.Skor);
        var pesan = m.Identitas == sesi.Identitas
            ? $"Jari ini sudah terdaftar sebagai {m.Jari} milik orang yang sama. Pilih jari lain."
            : $"Jari ini mirip jari yang sudah terdaftar atas nama {m.Identitas} (skor {Bulat(m.Skor)}). Tidak didaftarkan.";
        return Hasil.Galat(409, pesan, new { tahap = "gagal", identitas_lain = m.Identitas, jari_lain = m.Jari, skor = Bulat(m.Skor) });
    }

    static bool Kedaluwarsa(long dibuat) => Environment.TickCount64 - dibuat > UmurSesiDetik * 1000L;

    // Sesi pendaftaran yang masih berlaku, atau null. Bentuknya diperiksa
    // dulu, supaya kiriman sembarang tidak dipakai sebagai kunci.
    SesiDaftar? CariSesiDaftar(string? id)
    {
        if (!Brankas.TantanganSah(id) || !_sesiDaftar.TryGetValue(id!, out var sesi))
        {
            return null;
        }
        if (Kedaluwarsa(sesi.Dibuat))
        {
            _sesiDaftar.Remove(id!);
            return null;
        }
        return sesi;
    }

    static Hasil SesiTidakAda() => Hasil.Galat(404, "Sesi tidak ada atau sudah kedaluwarsa. Mulai lagi.");

    // Membuang sesi yang kedaluwarsa, lalu yang tertua selama golongannya masih
    // penuh. Sesi yang ditinggalkan halaman tidak boleh menahan pendaftaran
    // berikutnya. Tanpa golongan, semua sesi dihitung bersama.
    static void BuangSesiLama<T>(Dictionary<string, T> sesi, Func<T, long> dibuat, Func<T, bool>? golongan = null)
    {
        foreach (var kunci in sesi.Where(kv => Kedaluwarsa(dibuat(kv.Value))).Select(kv => kv.Key).ToArray())
        {
            sesi.Remove(kunci);
        }
        while (true)
        {
            var segolongan = sesi.Where(kv => golongan is null || golongan(kv.Value)).ToArray();
            if (segolongan.Length < MaksSesi)
            {
                return;
            }
            sesi.Remove(segolongan.MinBy(kv => dibuat(kv.Value)).Key);
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
            // Calon: daftar tetap, ditambah DPI yang dilaporkan alat untuk
            // gambar sesi ini.
            var dpiAlat = gambar.Select(g => g.Value.DpiAlat).OfType<int>().Where(DpiMasukAkal).Distinct().Order().ToArray();
            var hasil = DpiKalibrasi.Concat(dpiAlat).Distinct().Order().Select(dpi => HitungKalibrasi(dpi, gambar)).ToArray();
            _log.LogInformation("Kalibrasi DPI dari {Gambar} gambar.", gambar.Length);
            return Hasil.Oke(new
            {
                status = "ok",
                dpi_sekarang = _galeri.Dpi,
                dpi_alat = dpiAlat,
                gambar = gambar.Length,
                identitas = gambar.Select(g => g.Key.Identitas).Distinct().Count(),
                hasil,
            });
        }
    }

    Hasil Terapkan(int dpiBaru)
    {
        if (!DpiMasukAkal(dpiBaru))
        {
            return Hasil.Galat(400, $"DPI harus antara {DpiTerkecil} dan {DpiTerbesar}.");
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

    // Hanya menghapus identitas uji:. Galeri yang sama memuat pendaftaran
    // siswa dan guru, dan itu tidak boleh ikut terhapus dari halaman uji.
    public Hasil HapusDataUji()
    {
        int dihapus, sisaTemplat;
        lock (_kunciUbah)
        {
            var galeri = _galeri;
            var sisa = galeri.Entri.Where(e => !Brankas.IdentitasUji(e.Identitas)).ToImmutableArray();
            dihapus = galeri.Entri.Length - sisa.Length;
            sisaTemplat = sisa.Length;
            var tertolak = _rekamanTertolak.Where(r => !Brankas.IdentitasUji(r.Identitas)).ToImmutableArray();
            TulisGaleri(sisa, tertolak);
            _rekamanTertolak = tertolak;
            _galeri = new Galeri(sisa.IsEmpty ? DpiBawaan : galeri.Dpi, sisa);
            _gambar.Clear();
            _probeTerakhir = null;
            _kembar.Kosongkan();
        }
        _log.LogWarning("Data uji dihapus: {Dihapus} templat. Templat siswa dan guru yang tersisa: {Sisa}.", dihapus, sisaTemplat);
        return Hasil.Oke(new
        {
            status = "ok",
            dihapus,
            sisa = sisaTemplat,
            message = "Semua templat uji, gambar di memori, dan catatan sampel dihapus."
                      + (sisaTemplat > 0 ? $" {sisaTemplat} templat siswa dan guru tidak disentuh." : ""),
        });
    }

    // Rekaman tertolak ikut ditulis kembali apa adanya, supaya tidak hilang
    // diam-diam saat galeri berubah.
    void TulisGaleri(ImmutableArray<Entri> entri) => TulisGaleri(entri, _rekamanTertolak);

    // Untuk pemanggil yang juga mengurangi rekaman tertolak: keduanya ditulis
    // dulu, dan memori baru diganti pemanggil setelah penulisannya berhasil.
    void TulisGaleri(ImmutableArray<Entri> entri, ImmutableArray<RekamanTemplat> tertolak) =>
        _brankas.TulisTemplat(entri.Select(e => e.Rekaman).Concat(tertolak));

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
        && int.TryParse(versi.AsSpan(AwalanVersi.Length), out var dpi) && DpiMasukAkal(dpi)
            ? dpi
            : null;

    static bool DpiMasukAkal(int dpi) => dpi is >= DpiTerkecil and <= DpiTerbesar;

    // DPI yang dilaporkan alat, kalau keempat sampel menyebut angka yang sama
    // dan angkanya masuk akal. PNG tidak membawa DPI.
    static int? DpiSampel(Sampel[] sampel)
    {
        var dpi = sampel[0].DpiAlat;
        return dpi is int nilai && DpiMasukAkal(nilai) && sampel.All(s => s.DpiAlat == dpi) ? nilai : null;
    }

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

enum Tahap { MenungguIzin, Menangkap, MenungguUji, Lulus }

// Satu sesi pendaftaran berizin. Calon berisi templat tempelan yang masih
// dipakai; gambarnya sendiri tidak disimpan.
sealed class SesiDaftar(string identitas, string jari)
{
    public string Identitas { get; } = identitas;
    public string Jari { get; } = jari;
    public long Dibuat { get; } = Environment.TickCount64;
    public Tahap Tahap { get; set; } = Tahap.MenungguIzin;
    public int Dpi { get; set; }
    public List<FingerprintTemplate> Calon { get; } = [];
    public int Tempelan { get; set; }
    public int Uji { get; set; }
    public double[] Keserasian { get; set; } = [];
}

sealed record SesiCabut(string Identitas, long Dibuat);

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

sealed record PermintaanMulaiDaftar(string? Identitas, string? Jari);

sealed record PermintaanIzin(string? Sesi, string?[]? Izin);

sealed record PermintaanTempel(string? Sesi, string? Format, string? Sampel);

sealed record PermintaanSelesai(string? Sesi, string? Tantangan);

sealed record PermintaanMulaiCabut(string? Identitas);

sealed record PermintaanCabut(string? Sesi, string?[]? Izin, string? Tantangan);
