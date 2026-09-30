// Brankas.cs — kunci perangkat, tanda tangan, dan enkripsi templat.
//
// Saat jembatan pertama kali jalan, tiga hal dibuat lalu disimpan di
// kunci.bin di folder data:
// - ID perangkat, yang menamai kiosk ini di server. Bukan rahasia;
// - kunci HMAC, untuk menandatangani hasil jembatan. Server memegang
//   salinannya setelah dipasangkan di sub-langkah 4.2;
// - kunci templat, untuk mengenkripsi templat sidik jari. Kunci ini TIDAK
//   PERNAH meninggalkan PC kiosk, jadi salinan templat di server tidak bisa
//   dibuka di sana.
//
// Di Windows, kunci.bin dilindungi DPAPI LocalMachine, sehingga berkasnya
// tidak berguna kalau disalin ke PC lain. Yang menjaganya dari akun kiosk
// adalah ACL folder data, dipasang pasang-layanan.ps1. Di luar Windows, yang
// hanya boleh dalam mode pengembangan, kunci.bin disimpan tanpa enkripsi.
//
// Kalau kunci.bin ada tetapi tidak bisa dibuka, atau hilang padahal
// templat.json ada, jembatan berhenti. Kunci baru tidak pernah dibuat
// diam-diam, karena itu memutus pasangan dengan server dan membuat semua
// templat tidak terbaca.
//
// Tanda tangan hanya dibuat untuk pesan yang disusun berkas ini sendiri,
// dengan bentuk tetap per tujuan. Tidak ada fungsi untuk menandatangani isi
// sembarang. Setiap kolom diperiksa polanya sebelum digabung, jadi pemisah |
// tidak bisa disusupkan:
//   SJ1|absen|<perangkat>|<tantangan>|<identitas>|<skor>
//   SJ1|detak|<perangkat>|<tantangan>|alat:<0 atau 1>

using System.Globalization;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;
using Microsoft.Extensions.Logging;

sealed partial class Brankas
{
    public const string VersiPesan = "SJ1";

    const string NamaBerkasKunci = "kunci.bin";
    const string NamaBerkasTemplat = "templat.json";
    const int PanjangKunci = 32;
    const int PanjangNonce = 12;
    const int PanjangTag = 16;

    // Pembeda untuk DPAPI, supaya blob ini tidak tertukar dengan blob program
    // lain yang juga memakai DPAPI LocalMachine.
    static readonly byte[] EntropiDpapi = "JembatanSidikJari/kunci/v1"u8.ToArray();

    static readonly JsonSerializerOptions OpsiJson = new() { PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower };

    readonly string _folder;
    readonly byte[] _kunciHmac;
    readonly byte[] _kunciTemplat;
    readonly Lock _kunciBerkas = new();

    public string Perangkat { get; }

    Brankas(string folder, string perangkat, byte[] kunciHmac, byte[] kunciTemplat)
    {
        _folder = folder;
        Perangkat = perangkat;
        _kunciHmac = kunciHmac;
        _kunciTemplat = kunciTemplat;
    }

    // Membuka brankas di folder data, atau membuatnya kalau belum ada.
    public static Brankas Buka(string folder, ILogger log)
    {
        Directory.CreateDirectory(folder);
        if (!OperatingSystem.IsWindows())
        {
            File.SetUnixFileMode(folder, UnixFileMode.UserRead | UnixFileMode.UserWrite | UnixFileMode.UserExecute);
        }

        var jalur = Path.Combine(folder, NamaBerkasKunci);
        IsiKunci isi;
        if (File.Exists(jalur))
        {
            try
            {
                isi = BacaKunci(jalur);
            }
            catch (Exception e) when (e is CryptographicException or JsonException or FormatException or IOException or UnauthorizedAccessException)
            {
                throw new BrankasRusakException(
                    $"{NamaBerkasKunci} di {folder} tidak bisa dibuka. Jangan dihapus: kunci baru memutus pasangan "
                    + "dengan server dan membuat semua templat tidak terbaca.", e);
            }
        }
        else
        {
            // Kunci hilang padahal templat ada: kunci baru tidak akan bisa
            // membuka templat itu. Berhenti, supaya admin memulihkan kunci.bin
            // atau menghapus templat.json dengan sengaja.
            if (File.Exists(Path.Combine(folder, NamaBerkasTemplat)))
            {
                throw new BrankasRusakException(
                    $"{NamaBerkasKunci} tidak ada di {folder}, padahal {NamaBerkasTemplat} ada. Pulihkan {NamaBerkasKunci}, "
                    + $"atau hapus {NamaBerkasTemplat} dengan sengaja lalu daftarkan ulang semua jari.");
            }
            isi = BuatKunci();
            TulisAman(jalur, SandikanKunci(isi), bolehTimpa: false);
            log.LogWarning("Kunci perangkat baru dibuat untuk {Perangkat} di {Folder}.", isi.Perangkat, folder);
        }
        if (!OperatingSystem.IsWindows())
        {
            log.LogWarning("{Berkas} disimpan tanpa enkripsi. Hanya untuk mode pengembangan.", NamaBerkasKunci);
        }
        return new Brankas(folder, isi.Perangkat,
                           Convert.FromBase64String(isi.KunciHmac), Convert.FromBase64String(isi.KunciTemplat));
    }

    public static bool TantanganSah(string? tantangan) => Cocok(PolaTantangan(), tantangan);

    public TandaTangan TandatanganiAbsen(string tantangan, string identitas, int skor)
    {
        Wajib(PolaTantangan(), tantangan, nameof(tantangan));
        Wajib(PolaIdentitas(), identitas, nameof(identitas));
        if (skor is < 0 or > 9999)
        {
            throw new ArgumentOutOfRangeException(nameof(skor), "Skor harus 0 sampai 9999.");
        }
        return Tandatangani(string.Join('|', VersiPesan, "absen", Perangkat, tantangan, identitas,
                                        skor.ToString(CultureInfo.InvariantCulture)));
    }

    public TandaTangan TandatanganiDetak(string tantangan, bool alatTerhubung)
    {
        Wajib(PolaTantangan(), tantangan, nameof(tantangan));
        return Tandatangani(string.Join('|', VersiPesan, "detak", Perangkat, tantangan,
                                        alatTerhubung ? "alat:1" : "alat:0"));
    }

    public RekamanTemplat Enkripsi(Templat templat)
    {
        if (!MetadataSah(templat.Identitas, templat.Jari, templat.Urutan, templat.Versi) || templat.Data.Length == 0)
        {
            throw new ArgumentException("Metadata atau isi templat tidak sah.", nameof(templat));
        }
        var nonce = RandomNumberGenerator.GetBytes(PanjangNonce);
        var sandi = new byte[templat.Data.Length + PanjangTag];
        using var aes = new AesGcm(_kunciTemplat, PanjangTag);
        aes.Encrypt(nonce, templat.Data, sandi.AsSpan(0, templat.Data.Length), sandi.AsSpan(templat.Data.Length),
                    DataTerikat(templat.Identitas, templat.Jari, templat.Urutan, templat.Versi));
        return new RekamanTemplat(templat.Identitas, templat.Jari, templat.Urutan, templat.Versi,
                                  Convert.ToBase64String(nonce), Convert.ToBase64String(sandi));
    }

    // Gagal dengan CryptographicException kalau rekamannya diubah, tertukar
    // dengan identitas atau jari lain, atau dibuat dengan kunci lain.
    public Templat Dekripsi(RekamanTemplat rekaman)
    {
        if (!MetadataSah(rekaman.Identitas, rekaman.Jari, rekaman.Urutan, rekaman.Versi))
        {
            throw new CryptographicException("Rekaman templat rusak.");
        }
        byte[] nonce, sandi;
        try
        {
            nonce = Convert.FromBase64String(rekaman.Nonce);
            sandi = Convert.FromBase64String(rekaman.Sandi);
        }
        catch (Exception e) when (e is FormatException or ArgumentNullException)
        {
            throw new CryptographicException("Rekaman templat rusak.", e);
        }
        if (nonce.Length != PanjangNonce || sandi.Length <= PanjangTag)
        {
            throw new CryptographicException("Rekaman templat rusak.");
        }
        var data = new byte[sandi.Length - PanjangTag];
        using var aes = new AesGcm(_kunciTemplat, PanjangTag);
        aes.Decrypt(nonce, sandi.AsSpan(0, data.Length), sandi.AsSpan(data.Length), data,
                    DataTerikat(rekaman.Identitas, rekaman.Jari, rekaman.Urutan, rekaman.Versi));
        return new Templat(rekaman.Identitas, rekaman.Jari, rekaman.Urutan, rekaman.Versi, data);
    }

    // Rekaman terenkripsi di templat.json, apa adanya. Dekripsi per rekaman
    // oleh pemanggil, supaya satu rekaman rusak tidak menyembunyikan yang lain.
    // Berkas yang bukan JSON sah melempar JsonException.
    public IReadOnlyList<RekamanTemplat> BacaTemplat()
    {
        var jalur = Path.Combine(_folder, NamaBerkasTemplat);
        lock (_kunciBerkas)
        {
            if (!File.Exists(jalur))
            {
                return [];
            }
            return JsonSerializer.Deserialize<List<RekamanTemplat>>(File.ReadAllBytes(jalur), OpsiJson) ?? [];
        }
    }

    public void TulisTemplat(IEnumerable<RekamanTemplat> semua)
    {
        var isi = JsonSerializer.SerializeToUtf8Bytes(semua.ToList(), OpsiJson);
        lock (_kunciBerkas)
        {
            TulisAman(Path.Combine(_folder, NamaBerkasTemplat), isi, bolehTimpa: true);
        }
    }

    TandaTangan Tandatangani(string pesan) =>
        new(pesan, Convert.ToHexStringLower(HMACSHA256.HashData(_kunciHmac, Encoding.ASCII.GetBytes(pesan))));

    // Tiap rekaman terikat pada identitas, jari, urutan, dan versinya. Rekaman
    // yang dipindah ke baris lain gagal didekripsi.
    static byte[] DataTerikat(string identitas, string jari, int urutan, string versi) =>
        Encoding.ASCII.GetBytes(string.Join('|', VersiPesan, "templat", identitas, jari,
                                            urutan.ToString(CultureInfo.InvariantCulture), versi));

    internal static bool MetadataSah(string? identitas, string? jari, int urutan, string? versi) =>
        Cocok(PolaIdentitas(), identitas) && Cocok(PolaJari(), jari) && urutan is >= 1 and <= 9
        && Cocok(PolaVersi(), versi);

    static IsiKunci BuatKunci() => new(
        Versi: 1,
        Perangkat: "kiosk-" + Convert.ToHexStringLower(RandomNumberGenerator.GetBytes(3)),
        KunciHmac: Convert.ToBase64String(RandomNumberGenerator.GetBytes(PanjangKunci)),
        KunciTemplat: Convert.ToBase64String(RandomNumberGenerator.GetBytes(PanjangKunci)),
        Dibuat: DateTimeOffset.Now.ToString("yyyy-MM-dd'T'HH:mm:sszzz", CultureInfo.InvariantCulture));

    static byte[] SandikanKunci(IsiKunci isi)
    {
        var json = JsonSerializer.SerializeToUtf8Bytes(isi, OpsiJson);
        if (OperatingSystem.IsWindows())
        {
            return ProtectedData.Protect(json, EntropiDpapi, DataProtectionScope.LocalMachine);
        }
        return json;
    }

    static IsiKunci BacaKunci(string jalur)
    {
        var json = File.ReadAllBytes(jalur);
        if (OperatingSystem.IsWindows())
        {
            json = ProtectedData.Unprotect(json, EntropiDpapi, DataProtectionScope.LocalMachine);
        }
        var isi = JsonSerializer.Deserialize<IsiKunci>(json, OpsiJson);
        if (isi is null || isi.Versi != 1 || !Cocok(PolaPerangkat(), isi.Perangkat)
            || PanjangBase64(isi.KunciHmac) != PanjangKunci || PanjangBase64(isi.KunciTemplat) != PanjangKunci)
        {
            throw new FormatException("Isi kunci.bin tidak sah.");
        }
        return isi;
    }

    static int PanjangBase64(string? teks) => teks is null ? -1 : Convert.FromBase64String(teks).Length;

    // Tulis ke berkas sementara, paksa sampai ke disk, lalu ganti nama. Listrik
    // yang padam di tengah penulisan tidak meninggalkan berkas setengah jadi.
    static void TulisAman(string jalur, byte[] isi, bool bolehTimpa)
    {
        var sementara = jalur + ".baru";
        File.Delete(sementara);
        var opsi = new FileStreamOptions { Mode = FileMode.CreateNew, Access = FileAccess.Write, Share = FileShare.None };
        if (!OperatingSystem.IsWindows())
        {
            opsi.UnixCreateMode = UnixFileMode.UserRead | UnixFileMode.UserWrite;
        }
        try
        {
            using (var berkas = new FileStream(sementara, opsi))
            {
                berkas.Write(isi);
                berkas.Flush(flushToDisk: true);
            }
            File.Move(sementara, jalur, bolehTimpa);
        }
        finally
        {
            File.Delete(sementara);
        }
    }

    static bool Cocok(Regex pola, string? nilai) => nilai is not null && pola.IsMatch(nilai);

    static void Wajib(Regex pola, string? nilai, string nama)
    {
        if (!Cocok(pola, nilai))
        {
            throw new ArgumentException(nama + " tidak sah.", nama);
        }
    }

    // \A dan \z, bukan ^ dan $: di .NET, $ juga cocok sebelum baris baru di
    // ujung teks.
    [GeneratedRegex(@"\A[a-z0-9-]{1,32}\z")]
    private static partial Regex PolaPerangkat();

    [GeneratedRegex(@"\A[0-9a-f]{64}\z")]
    private static partial Regex PolaTantangan();

    // uji: hanya untuk prototipe 4.1. Server tidak akan menerimanya.
    [GeneratedRegex(@"\A(?:(?:siswa|guru):[1-9][0-9]{0,9}|uji:[A-Z0-9-]{1,16})\z")]
    private static partial Regex PolaIdentitas();

    [GeneratedRegex(@"\A[a-z0-9-]{1,24}\z")]
    private static partial Regex PolaJari();

    [GeneratedRegex(@"\A[a-z0-9][a-z0-9.-]{0,31}\z")]
    private static partial Regex PolaVersi();
}

// Templat dalam bentuk terbuka. Hanya ada di memori jembatan.
sealed record Templat(string Identitas, string Jari, int Urutan, string Versi, byte[] Data);

// Templat terenkripsi. Bentuk inilah yang disimpan di templat.json, dan mulai
// 4.2 juga di server. Nonce dan Sandi dalam base64; Sandi berisi teks sandi
// diikuti tag GCM 16 byte.
sealed record RekamanTemplat(string Identitas, string Jari, int Urutan, string Versi, string Nonce, string Sandi);

sealed record TandaTangan(string Pesan, string Hmac);

// Isi kunci.bin. Kunci dalam base64.
sealed record IsiKunci(int Versi, string Perangkat, string KunciHmac, string KunciTemplat, string Dibuat);

// kunci.bin tidak bisa dibuka, atau hilang padahal templat ada. Jembatan
// harus berhenti.
sealed class BrankasRusakException(string pesan, Exception? sebab = null) : Exception(pesan, sebab);
