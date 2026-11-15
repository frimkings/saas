{{--
    The page around a slip: one A5 slip, or two copies side by side on A4 landscape (patient and clinic).
    Needs $paper, $body (the slip's body view) and everything that view needs.
--}}
@php $paper = $paper ?? \App\Support\Clinical\Slip::A5; @endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $pageTitle }}</title>
    @include('pdf.slip.styles', ['paper' => $paper])
</head>
<body>
@if($paper === \App\Support\Clinical\Slip::A4_TWO)
    <table class="copies"><tr>
        <td class="first"><div class="copy-label">Patient copy</div>@include($body)</td>
        <td class="second"><div class="copy-label">Clinic copy</div>@include($body)</td>
    </tr></table>
@else
    @include($body)
@endif
</body>
</html>
