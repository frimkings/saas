@if($refraction->hasStructuredObjective())
<div style="margin:10px 0 5px;font-weight:bold;">Objective Refraction{{ $refraction->objective_method ? ' — '.str($refraction->objective_method)->replace('_', ' ')->title() : '' }}</div>
<table class="refraction-table">
 <thead><tr><th>Eye</th><th>Sphere</th><th>Cylinder</th><th>Axis</th><th>VA</th></tr></thead>
 <tbody>
 @foreach(['od' => 'OD (Right)', 'os' => 'OS (Left)'] as $eye => $label)
  <tr><td><strong>{{ $label }}</strong></td>
   <td>{{ \App\Models\Refractions::formatPower($refraction->{'objective_'.$eye.'_sphere'}) ?? '—' }}</td>
   <td>{{ \App\Models\Refractions::formatPower($refraction->{'objective_'.$eye.'_cylinder'}) ?? '—' }}</td>
   <td>{{ $refraction->{'objective_'.$eye.'_axis'} ? str_pad($refraction->{'objective_'.$eye.'_axis'}, 3, '0', STR_PAD_LEFT).'°' : '—' }}</td>
   <td>{{ $refraction->{'objective_'.$eye.'_va'} ?? '—' }}</td></tr>
 @endforeach
 </tbody>
</table>
@if($refraction->objective_notes)<div style="margin-top:6px;"><strong>Objective Notes:</strong> {{ $refraction->objective_notes }}</div>@endif
@endif
