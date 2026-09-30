// jembatan-sidik-jari — layanan kecil di PC kiosk.
//
// Peramban kiosk menangkap sidik jari lewat HID Authentication Device Client
// dan mengirimkannya ke sini. Jembatan mencocokkannya 1:N lalu menandatangani
// hasilnya (Pencocok.cs dan Brankas.cs), sehingga server bisa menolak
// absensi yang tidak lewat kiosk.
//
// Berkas ini pintu masuknya. Aturannya:
// - hanya mendengar di 127.0.0.1, jadi tidak terjangkau dari jaringan;
// - Host selain 127.0.0.1/localhost ditolak, sebagai penangkal DNS rebinding;
// - Origin yang tidak terdaftar untuk rute itu ditolak SEBELUM permintaannya
//   diproses. CORS saja tidak cukup: peramban tetap mengirim permintaan
//   sederhana tanpa preflight, dan hanya jawabannya yang disembunyikan;
// - POST wajib application/json, supaya kiriman lintas asal selalu melewati
//   preflight;
// - tidak ada rute yang mengembalikan templat atau gambar, dan tidak ada rute
//   yang menandatangani isi kiriman pemanggil.
//
// Argumen. Untuk layanan Windows argumen ditulis di binPath, yang hanya bisa
// diubah admin:
//   --port <n>       bawaan 47890
//   --data <folder>  bawaan %ProgramData%\JembatanSidikJari
//   --pengembangan   uji di luar PC kiosk (Mac). Ditolak kalau berjalan
//                    sebagai layanan, dan wajib di luar Windows.

using System.Globalization;
using System.Management;
using System.Net;
using System.Reflection;
using System.Runtime.InteropServices;
using System.Text.Json;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Server.Kestrel.Core;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Hosting.WindowsServices;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Logging.EventLog;
using Microsoft.Net.Http.Headers;

const string NamaLayanan = "JembatanSidikJari";

// Dipakai sampai wwwroot/uji.html ada.
const string HalamanSementara = """
    <!doctype html>
    <html lang="id"><head><meta charset="utf-8"><title>Jembatan sidik jari</title></head>
    <body><p>Jembatan sidik jari berjalan. Halaman uji belum dipasang.</p></body></html>
    """;

Argumen arg;
try
{
    arg = Argumen.Urai(args);
}
catch (ArgumentException e)
{
    Console.Error.WriteLine("Argumen tidak sah: " + e.Message);
    return 2;
}

var sebagaiLayanan = WindowsServiceHelpers.IsWindowsService();
if (arg.Pengembangan && sebagaiLayanan)
{
    // Mode pengembangan kelak membuka jalur uji tanpa alat. Jalur itu tidak
    // boleh sampai ke layanan di PC kiosk, walaupun argumennya tertulis di
    // binPath.
    Console.Error.WriteLine("--pengembangan tidak boleh dipakai saat berjalan sebagai layanan.");
    return 2;
}
if (!arg.Pengembangan && !OperatingSystem.IsWindows())
{
    Console.Error.WriteLine("Di luar Windows, jembatan hanya berjalan dengan --pengembangan.");
    return 2;
}

var folderData = arg.Data ?? (OperatingSystem.IsWindows()
    ? Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), NamaLayanan)
    : Path.Combine(Path.GetTempPath(), "jembatan-sidik-jari-pengembangan"));

var builder = WebApplication.CreateBuilder(new WebApplicationOptions
{
    // Argumen sudah diurai di atas. Host tidak ikut membacanya, jadi --urls
    // dan sejenisnya tidak bisa membuka alamat lain.
    Args = [],
    // Tanpa halaman galat pengembang: rincian galat tidak pernah sampai ke
    // pemanggil.
    EnvironmentName = Environments.Production,
    // Layanan Windows berjalan dengan folder kerja C:\Windows\System32.
    ContentRootPath = AppContext.BaseDirectory,
});

// Tidak ada appsettings.json maupun variabel lingkungan yang dibaca. Tanpa
// ini, berkas konfigurasi di samping .exe bisa menambah alamat Kestrel.
builder.Configuration.Sources.Clear();

builder.WebHost.ConfigureKestrel(kestrel =>
{
    kestrel.AddServerHeader = false;
    kestrel.Limits.MaxRequestBodySize = 2 * 1024 * 1024;
    kestrel.Listen(IPAddress.Loopback, arg.Port, l => l.Protocols = HttpProtocols.Http1);
});

builder.Logging.ClearProviders();
builder.Logging.AddSimpleConsole(o =>
{
    o.SingleLine = true;
    o.TimestampFormat = "HH:mm:ss ";
});
builder.Logging.AddFilter("Microsoft", LogLevel.Warning);

// Sebagai layanan, log Warning ke atas masuk Event Viewer > Application.
// Sumbernya didaftarkan pasang-layanan.ps1, karena mendaftarkan sumber log
// butuh hak admin.
builder.Services.AddWindowsService(o => o.ServiceName = NamaLayanan);
builder.Services.Configure<EventLogSettings>(s =>
{
    if (OperatingSystem.IsWindows())
    {
        s.SourceName = NamaLayanan;
        s.LogName = "Application";
    }
});

var app = builder.Build();
var log = app.Logger;

// Tanpa brankas jembatan tidak berguna, dan kunci baru tidak boleh dibuat
// diam-diam. Jadi kalau gagal dibuka, berhenti.
Brankas brankas;
try
{
    brankas = Brankas.Buka(folderData, log);
}
catch (Exception e) when (e is BrankasRusakException or IOException or UnauthorizedAccessException)
{
    log.LogCritical(e, "Jembatan berhenti: brankas di {Folder} tidak bisa dibuka.", folderData);
    await app.DisposeAsync();
    return 1;
}

// templat.json yang rusak tidak boleh menjadi galeri kosong diam-diam, karena
// semua siswa akan "tidak dikenali". Jadi berhenti juga.
Pencocok pencocok;
try
{
    pencocok = Pencocok.Muat(brankas, log);
}
catch (Exception e) when (e is JsonException or IOException or UnauthorizedAccessException)
{
    log.LogCritical(e, "Jembatan berhenti: templat.json di {Folder} tidak bisa dibaca.", folderData);
    await app.DisposeAsync();
    return 1;
}

var versi = typeof(Program).Assembly.GetCustomAttribute<AssemblyInformationalVersionAttribute>()?.InformationalVersion ?? "?";
var mode = sebagaiLayanan ? "layanan" : arg.Pengembangan ? "pengembangan" : "konsol";
var cekAlat = new CekAlat(log);
var rute = new PetaRute(app);

app.UseExceptionHandler(galat => galat.Run(async ctx =>
{
    ctx.Response.StatusCode = StatusCodes.Status500InternalServerError;
    await ctx.Response.WriteAsJsonAsync(new { status = "error", message = "Terjadi galat di jembatan. Rinciannya ada di log jembatan." });
}));

app.Use(async (ctx, lanjut) =>
{
    var h = ctx.Response.Headers;
    h.CacheControl = "no-store";
    h.XContentTypeOptions = "nosniff";
    h.XFrameOptions = "DENY";
    h["Referrer-Policy"] = "no-referrer";
    h.Vary = "Origin";

    var jawaban = PenjagaAsal.Periksa(ctx, arg.Port, rute);
    if (jawaban is null)
    {
        await lanjut(ctx);
        return;
    }
    ctx.Response.StatusCode = jawaban.Kode;
    if (jawaban.Pesan is not null)
    {
        log.LogWarning("Ditolak {Kode}: {Metode} {Jalur}, Host {Host}, Origin {Origin}.",
            jawaban.Kode, ctx.Request.Method, ctx.Request.Path, ctx.Request.Host.Value, ctx.Request.Headers.Origin.ToString());
        await ctx.Response.WriteAsJsonAsync(new { status = "error", message = jawaban.Pesan });
    }
});

rute.Get("/", Asal.HalamanSendiri, () =>
{
    var halaman = typeof(Program).Assembly.GetManifestResourceStream("halaman/uji.html");
    return halaman is null
        ? Results.Content(HalamanSementara, "text/html; charset=utf-8")
        : Results.Stream(halaman, "text/html; charset=utf-8");
});

rute.Get("/status", Asal.HalamanSendiri | Asal.Kiosk, () => Results.Json(new
{
    status = "ok",
    layanan = "jembatan-sidik-jari",
    versi,
    mode,
    perangkat = brankas.Perangkat,
    waktu = DateTimeOffset.Now.ToString("yyyy-MM-dd'T'HH:mm:sszzz", CultureInfo.InvariantCulture),
    alat = cekAlat.Periksa(),
    galeri = pencocok.Ringkasan(),
}));

// Detak kiosk: status alat menurut pemeriksaan jembatan sendiri, bukan
// menurut halaman, ditandatangani bersama tantangan dari pemanggil.
rute.Post("/detak", Asal.HalamanSendiri | Asal.Kiosk, async (HttpRequest permintaan) =>
{
    var (isi, galat) = await BacaJson<PermintaanDetak>(permintaan);
    if (galat is not null)
    {
        return galat;
    }
    if (!Brankas.TantanganSah(isi!.Tantangan))
    {
        return Galat(StatusCodes.Status400BadRequest, "Tantangan tidak sah.");
    }
    var alat = cekAlat.Periksa();
    var terhubung = alat.Terhubung == true;
    var tanda = brankas.TandatanganiDetak(isi.Tantangan!, terhubung);
    return Results.Json(new
    {
        status = "ok",
        perangkat = brankas.Perangkat,
        alat = terhubung ? 1 : 0,
        keterangan_alat = alat.Keterangan,
        pesan = tanda.Pesan,
        tanda_tangan = tanda.Hmac,
    });
});

// Rute prototipe 4.1, hanya dari halaman uji jembatan sendiri. Identifikasi
// baru dibuka untuk halaman kiosk di 4.4.
rute.Get("/galeri", Asal.HalamanSendiri, () => Results.Json(pencocok.IsiGaleri()));
rute.Post("/identifikasi", Asal.HalamanSendiri, async (HttpRequest permintaan) =>
{
    var (isi, galat) = await BacaJson<PermintaanIdentifikasi>(permintaan);
    return galat ?? Kirim(pencocok.Identifikasi(isi!));
});
rute.Post("/daftar", Asal.HalamanSendiri, async (HttpRequest permintaan) =>
{
    var (isi, galat) = await BacaJson<PermintaanDaftar>(permintaan);
    return galat ?? Kirim(pencocok.Daftarkan(isi!));
});
rute.Post("/kalibrasi", Asal.HalamanSendiri, async (HttpRequest permintaan) =>
{
    var (isi, galat) = await BacaJson<PermintaanKalibrasi>(permintaan);
    return galat ?? Kirim(pencocok.Kalibrasi(isi!));
});
rute.Post("/ukur", Asal.HalamanSendiri, () => Kirim(pencocok.Ukur()));
rute.Post("/hapus-uji", Asal.HalamanSendiri, () => Kirim(pencocok.HapusDataUji()));

app.MapFallback(() => Results.Json(new { status = "error", message = "Rute tidak dikenal." },
    statusCode: StatusCodes.Status404NotFound));

app.Lifetime.ApplicationStarted.Register(() =>
{
    log.LogInformation("Jembatan sidik jari {Versi}, perangkat {Perangkat}, berjalan sebagai {Mode} di http://127.0.0.1:{Port}. Folder data: {Folder}",
        versi, brankas.Perangkat, mode, arg.Port, folderData);
    if (arg.Pengembangan)
    {
        log.LogWarning("MODE PENGEMBANGAN. Jangan dipakai di PC kiosk.");
    }
});

await app.RunAsync();
return 0;

static IResult Galat(int kode, string pesan) =>
    Results.Json(new { status = "error", message = pesan }, statusCode: kode);

static IResult Kirim(Hasil hasil) => Results.Json(hasil.Isi, statusCode: hasil.Kode);

// Isi kiriman JSON, atau jawaban galat yang siap dikirim. Kiriman lebih dari
// batas Kestrel (2 MB) dijawab 413.
static async Task<(T? Isi, IResult? Galat)> BacaJson<T>(HttpRequest permintaan) where T : class
{
    try
    {
        var isi = await permintaan.ReadFromJsonAsync<T>();
        return isi is null ? (null, Galat(StatusCodes.Status400BadRequest, "Kiriman kosong.")) : (isi, null);
    }
    catch (JsonException)
    {
        return (null, Galat(StatusCodes.Status400BadRequest, "Kiriman bukan JSON yang sah."));
    }
    catch (Microsoft.AspNetCore.Http.BadHttpRequestException e)
    {
        return (null, Galat(e.StatusCode, e.StatusCode == StatusCodes.Status413PayloadTooLarge
            ? "Kiriman terlalu besar."
            : "Kiriman tidak bisa dibaca."));
    }
}

// Siapa yang boleh memanggil sebuah rute.
[Flags]
enum Asal
{
    // Halaman yang disajikan jembatan ini sendiri, http://127.0.0.1:<port>.
    HalamanSendiri = 1,
    // Halaman kiosk di server sekolah.
    Kiosk = 2,
}

// Jawaban langsung dari penjaga. Pesan null berarti tanpa isi (preflight).
sealed record Jawaban(int Kode, string? Pesan);

static class PenjagaAsal
{
    public const string AsalKiosk = "https://smkt.alhasan.co.id";

    // null berarti permintaan boleh lanjut ke rutenya.
    public static Jawaban? Periksa(HttpContext ctx, int port, PetaRute rute)
    {
        var permintaan = ctx.Request;

        var host = permintaan.Host.Value ?? "";
        if (!host.Equals($"127.0.0.1:{port}", StringComparison.Ordinal)
            && !host.Equals($"localhost:{port}", StringComparison.OrdinalIgnoreCase))
        {
            return new Jawaban(StatusCodes.Status403Forbidden, "Host tidak dikenal.");
        }

        var aturan = rute.Aturan(permintaan.Path);
        var origin = permintaan.Headers.Origin.ToString();
        if (origin.Length > 0)
        {
            var asal = JenisAsal(origin, port);
            if (aturan is null || (aturan.Value.Asal & asal) == 0)
            {
                return new Jawaban(StatusCodes.Status403Forbidden, "Asal permintaan tidak diizinkan.");
            }
            if (asal == Asal.Kiosk)
            {
                ctx.Response.Headers.AccessControlAllowOrigin = origin;
            }
        }
        else if (!HttpMethods.IsGet(permintaan.Method))
        {
            // Peramban selalu mengirim Origin untuk POST. Tanpa Origin hanya
            // GET yang diterima, misalnya membuka halaman uji atau curl ke
            // /status.
            return new Jawaban(StatusCodes.Status403Forbidden, "Permintaan tanpa Origin hanya boleh GET.");
        }

        if (HttpMethods.IsOptions(permintaan.Method))
        {
            var metode = permintaan.Headers.AccessControlRequestMethod.ToString();
            if (aturan is null || metode != aturan.Value.Metode)
            {
                return new Jawaban(StatusCodes.Status403Forbidden, "Metode tidak diizinkan untuk rute ini.");
            }
            var h = ctx.Response.Headers;
            h.AccessControlAllowMethods = metode;
            h.AccessControlAllowHeaders = "Content-Type";
            h.AccessControlMaxAge = "600";
            return new Jawaban(StatusCodes.Status204NoContent, null);
        }

        if (HttpMethods.IsPost(permintaan.Method)
            && !(MediaTypeHeaderValue.TryParse(permintaan.ContentType, out var jenis)
                 && jenis.MediaType.Equals("application/json", StringComparison.OrdinalIgnoreCase)))
        {
            return new Jawaban(StatusCodes.Status415UnsupportedMediaType, "Kiriman harus application/json.");
        }
        return null;
    }

    static Asal JenisAsal(string origin, int port)
    {
        if (origin.Equals(AsalKiosk, StringComparison.Ordinal))
        {
            return Asal.Kiosk;
        }
        if (origin.Equals($"http://127.0.0.1:{port}", StringComparison.Ordinal)
            || origin.Equals($"http://localhost:{port}", StringComparison.OrdinalIgnoreCase))
        {
            return Asal.HalamanSendiri;
        }
        return 0;
    }
}

// Rute dan asal yang boleh memanggilnya didaftarkan bersamaan, supaya
// keduanya tidak pernah terpisah. Rute yang tidak terdaftar hanya bisa dibuka
// dengan GET tanpa Origin, dan tidak ada yang menjawabnya selain 404.
sealed class PetaRute(WebApplication app)
{
    readonly Dictionary<string, (Asal Asal, string Metode)> _aturan = new(StringComparer.OrdinalIgnoreCase);

    public void Get(string jalur, Asal asal, Delegate penangan)
    {
        Daftar(jalur, asal, HttpMethods.Get);
        app.MapGet(jalur, penangan);
    }

    public void Post(string jalur, Asal asal, Delegate penangan)
    {
        Daftar(jalur, asal, HttpMethods.Post);
        app.MapPost(jalur, penangan);
    }

    public (Asal Asal, string Metode)? Aturan(PathString jalur) =>
        _aturan.TryGetValue(jalur.Value ?? "", out var aturan) ? aturan : null;

    void Daftar(string jalur, Asal asal, string metode)
    {
        if (!_aturan.TryAdd(jalur, (asal, metode)))
        {
            throw new InvalidOperationException("Rute ganda: " + jalur);
        }
    }
}

// Pemeriksaan jembatan sendiri: apakah pembaca terpasang dan drivernya sehat
// menurut Windows. Tidak bergantung pada peramban, jadi detak tetap jujur
// walaupun halaman melaporkan hal lain.
sealed class CekAlat(ILogger log)
{
    // U.are.U 4000B/4500. Hardware ID di Device Manager harus diawali ini;
    // kalau berbeda, ubah di sini.
    public const string IdPerangkatKeras = @"USB\VID_05BA&PID_000A";

    // Hasil disimpan sebentar, supaya /status yang sering dipanggil tidak
    // membebani WMI.
    static readonly TimeSpan UmurHasil = TimeSpan.FromSeconds(5);

    readonly Lock _kunci = new();
    StatusAlat? _hasil;
    DateTime _diperiksa;

    public StatusAlat Periksa()
    {
        lock (_kunci)
        {
            if (_hasil is null || DateTime.UtcNow - _diperiksa > UmurHasil)
            {
                _hasil = PeriksaSekarang();
                _diperiksa = DateTime.UtcNow;
            }
            return _hasil;
        }
    }

    StatusAlat PeriksaSekarang()
    {
        if (!OperatingSystem.IsWindows())
        {
            return new StatusAlat(null, 0, "Pemeriksaan alat hanya ada di Windows.");
        }
        try
        {
            // Garis miring terbalik digandakan untuk WQL.
            var kueri = "SELECT Status FROM Win32_PnPEntity WHERE DeviceID LIKE '"
                      + IdPerangkatKeras.Replace(@"\", @"\\") + "%'";
            using var cari = new ManagementObjectSearcher(kueri);
            cari.Options.Timeout = TimeSpan.FromSeconds(5);
            using var hasil = cari.Get();
            int jumlah = 0, sehat = 0;
            foreach (var perangkat in hasil)
            {
                using (perangkat)
                {
                    jumlah++;
                    if (perangkat["Status"] as string == "OK")
                    {
                        sehat++;
                    }
                }
            }
            if (jumlah == 0)
            {
                return new StatusAlat(false, 0, "Alat tidak terpasang.");
            }
            return sehat > 0
                ? new StatusAlat(true, jumlah, "Alat terpasang dan drivernya sehat.")
                : new StatusAlat(false, jumlah, "Alat terpasang, tetapi drivernya bermasalah.");
        }
        catch (Exception e) when (e is ManagementException or COMException or UnauthorizedAccessException or TimeoutException)
        {
            log.LogWarning(e, "Pemeriksaan alat lewat WMI gagal.");
            return new StatusAlat(null, 0, "Pemeriksaan alat gagal. Rinciannya ada di log jembatan.");
        }
    }
}

// Terhubung null berarti tidak bisa diperiksa.
sealed record StatusAlat(bool? Terhubung, int Jumlah, string Keterangan);

sealed record PermintaanDetak(string? Tantangan);

sealed record Argumen(int Port, string? Data, bool Pengembangan)
{
    public const int PortBawaan = 47890;

    public static Argumen Urai(string[] args)
    {
        var port = PortBawaan;
        string? data = null;
        var pengembangan = false;
        for (var i = 0; i < args.Length; i++)
        {
            switch (args[i])
            {
                case "--port":
                    if (++i >= args.Length
                        || !int.TryParse(args[i], NumberStyles.None, CultureInfo.InvariantCulture, out port)
                        || port < 1024 || port > 65535)
                    {
                        throw new ArgumentException("--port butuh angka 1024 sampai 65535.");
                    }
                    break;
                case "--data":
                    if (++i >= args.Length || args[i].Length == 0)
                    {
                        throw new ArgumentException("--data butuh nama folder.");
                    }
                    data = Path.GetFullPath(args[i]);
                    break;
                case "--pengembangan":
                    pengembangan = true;
                    break;
                default:
                    throw new ArgumentException("tidak dikenal: " + args[i]);
            }
        }
        return new Argumen(port, data, pengembangan);
    }
}
