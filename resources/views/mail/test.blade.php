<x-mail::message>
# Email tes berhasil

Pengaturan SMTP **{{ $app }}** sudah benar: email ini dikirim lewat **{{ $provider }}** pada {{ $sentAt }} WIB.

<x-mail::panel>
Password sementara untuk akun baru, lupa password, reset 2FA, dan notifikasi keamanan sekarang bisa dikirim ke email pengguna.
</x-mail::panel>

<x-mail::button :url="$url">
Buka {{ $app }}
</x-mail::button>
</x-mail::message>
