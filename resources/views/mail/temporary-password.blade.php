<x-mail::message>
# {{ $isNew ? 'Akun Anda sudah dibuat' : 'Password sementara baru' }}

Halo **{{ $name }}**,

@if ($isNew)
{{ $admin }} membuatkan akun **{{ $app }}** untuk Anda. Gunakan data berikut untuk masuk:
@else
{{ $admin }} membuatkan password sementara baru untuk akun **{{ $app }}** Anda. Password lama tidak berlaku lagi.
@endif

<x-mail::panel>
Email<br>
**{{ $email }}**

Password sementara<br>
<code style="font-size: 17px; letter-spacing: 1px;">{{ $password }}</code>
</x-mail::panel>

<x-mail::button :url="$loginUrl">
Masuk sekarang
</x-mail::button>

Saat masuk pertama kali Anda wajib membuat password baru. Password sementara ini berlaku sampai **{{ $expiresAt }} WIB**; setelah itu minta admin mengirim yang baru.

<x-mail::subcopy>
Jangan teruskan email ini ke siapa pun. Admin tidak akan pernah menanyakan password Anda. Setelah masuk, aktifkan 2FA di Profil → Keamanan (2FA) untuk perlindungan tambahan.
</x-mail::subcopy>
</x-mail::message>
