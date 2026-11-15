{{-- Repeats on every PDF page: page x of y, the patient, and who generated the document. --}}
<div class="doc-foot">
    <table><tr>
        <td>{{ $patient?->name }} · {{ $patient?->pxnumber }}</td>
        <td style="text-align:center;">Generated {{ ($generatedAt ?? now())->format('d M Y, g:i A') }}@if(!empty($generatedBy)) by {{ $generatedBy->name }}@endif</td>
        {{-- "Page x of y" is stamped here after rendering (PatientMedicalRecordController::withPageNumbers). --}}
        <td style="width:22%;"></td>
    </tr></table>
</div>
