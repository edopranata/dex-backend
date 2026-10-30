<x-mail::message>
# Reset password

Halo **{{ $name }}**,

Kami menerima permintaan untuk membuat password baru akun **{{ $app }}** ({{ $email }}). Klik tombol di bawah untuk melanjutkan.

<x-mail::button :url="$url">
Buat password baru
</x-mail::button>

Link ini berlaku **{{ $minutes }} menit** dan hanya bisa dipakai sekali.

<x-mail::subcopy>
Tidak meminta reset? Abaikan email ini; password Anda tidak berubah. Jika tombol tidak berfungsi, salin link ini ke browser: <span class="break-all">[{{ $url }}]({{ $url }})</span>
</x-mail::subcopy>
</x-mail::message>
