<x-mail::message>
# Password Anda diganti

Halo **{{ $name }}**,

Password akun **{{ $app }}** ({{ $email }}) baru saja diganti pada **{{ $changedAt }} WIB**.

Jika itu Anda, tidak ada yang perlu dilakukan.

<x-mail::panel>
**Bukan Anda?** Segera buat password baru lewat *Lupa password?* di halaman masuk, lalu hubungi admin.
</x-mail::panel>

<x-mail::button :url="$forgotUrl" color="error">
Amankan akun saya
</x-mail::button>
</x-mail::message>
