<x-mail::message>
# 2FA dimatikan

Halo **{{ $name }}**,

Verifikasi dua langkah (2FA) akun **{{ $app }}** ({{ $email }}) dimatikan pada **{{ $disabledAt }} WIB** {{ $byAdmin ? 'oleh admin' : 'lewat link reset di email' }}.

Akun Anda sekarang hanya dilindungi password. Aktifkan lagi 2FA dengan authenticator baru:

<x-mail::button :url="$securityUrl">
Aktifkan 2FA lagi
</x-mail::button>

<x-mail::panel>
**Bukan Anda?** Segera ganti password lewat *Lupa password?* di halaman masuk dan hubungi admin.
</x-mail::panel>
</x-mail::message>
