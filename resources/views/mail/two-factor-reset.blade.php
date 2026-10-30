<x-mail::message>
# Reset 2FA

Halo **{{ $name }}**,

Seseorang yang mengetahui password akun **{{ $app }}** Anda ({{ $email }}) meminta mematikan verifikasi dua langkah (2FA) karena tidak bisa membuka aplikasi authenticator.

<x-mail::button :url="$url" color="error">
Matikan 2FA
</x-mail::button>

Link berlaku **{{ $minutes }} menit**. Setelah 2FA mati, Anda bisa masuk dengan password saja — aktifkan lagi 2FA di Profil → Keamanan (2FA) secepatnya.

<x-mail::panel>
**Bukan Anda?** Jangan klik tombol di atas. Password Anda kemungkinan sudah diketahui orang lain: segera ganti lewat *Lupa password?* dan hubungi admin.
</x-mail::panel>
</x-mail::message>
