<div class="{{ $optical ? 'ui-panel p-6 space-y-4 max-w-2xl' : 'p-4' }}">
    @php
        $hints = [
            'clinic_link'    => 'https://maps.app.goo.gl/...',
            'whatsapp_link'  => '0241234567 or https://wa.me/233241234567',
            'review_link'    => 'https://g.page/r/...',
            'website_link'   => 'https://www.yourclinic.com',
            'booking_link'   => 'https://www.yourclinic.com/book',
            'facebook_link'  => 'https://facebook.com/yourclinic',
            'instagram_link' => 'https://instagram.com/yourclinic',
            'tiktok_link'    => 'https://tiktok.com/@yourclinic',
        ];
    @endphp
    <div class="{{ $optical ? '' : 'card border-0 shadow-sm rounded-lg' }}" style="{{ $optical ? '' : 'max-width:900px' }}">
        <div class="{{ $optical ? 'space-y-4' : 'card-body' }}">
            <h5 class="{{ $optical ? 'text-sm font-semibold text-slate-900' : 'font-weight-bold mb-1' }}"><i class="fas fa-link {{ $optical ? '' : 'mr-1 text-primary' }}"></i> Clinic links</h5>
            <p class="{{ $optical ? 'ui-muted text-xs' : 'small text-muted mb-3' }}">
                Set these once; every SMS template can insert them by name (Communications &rarr; Messages). A message that uses a link you
                have not set is not sent, rather than going out with a gap. Short links (maps.app.goo.gl, g.page) keep messages to one SMS.
            </p>

            <form wire:submit.prevent="save" class="{{ $optical ? 'ui-form space-y-4' : '' }}">
                <div class="{{ $optical ? 'grid grid-cols-1 gap-4' : 'row' }}">
                    @foreach(\App\Support\Messaging\ClinicLinks::LINKS as $placeholder => [$column, $label])
                        <div class="{{ $optical ? 'ui-field' : 'form-group col-md-6' }}">
                            <label class="{{ $optical ? '' : 'small font-weight-bold text-muted' }}" for="link-{{ $column }}">
                                {{ $label }} <code class="{{ $optical ? 'text-xs' : 'small' }}">{{ $placeholder }}</code>
                            </label>
                            <input type="text" id="link-{{ $column }}" wire:model.live.debounce.500ms="links.{{ $column }}" maxlength="500"
                                   placeholder="{{ $hints[$column] ?? 'https://' }}"
                                   class="{{ $optical ? 'ui-input' : 'form-control bg-light border-0' }} @error('links.'.$column) is-invalid @enderror">
                            @error('links.'.$column)
                                <small class="{{ $optical ? 'text-xs text-red-600' : 'text-danger small' }}">{{ $message }}</small>
                            @else
                                @if(strlen($links[$column] ?? '') > 40)
                                    <small class="{{ $optical ? 'text-xs text-amber-700' : 'text-warning small' }}">{{ strlen($links[$column]) }} characters — a short link would save SMS credits.</small>
                                @endif
                            @enderror
                            @if($column === 'clinic_link')
                                <small class="{{ $optical ? 'ui-muted text-xs' : 'form-text text-muted' }}">Messages still using the old <code>[LINK]</code> get this link too.</small>
                            @endif
                        </div>
                    @endforeach
                </div>

                @if($branchLinks)
                    <div class="{{ $optical ? 'border-t border-slate-200 pt-4' : 'border-top pt-3 mt-2' }}">
                        <h6 class="{{ $optical ? 'text-sm font-semibold text-slate-900' : 'font-weight-bold' }}">Branch location &amp; WhatsApp</h6>
                        <p class="{{ $optical ? 'ui-muted text-xs' : 'small text-muted' }}">Leave empty to use the clinic's links above. A branch's own link goes in messages about that branch.</p>
                        <div class="{{ $optical ? 'overflow-x-auto' : 'table-responsive' }}">
                            <table class="{{ $optical ? 'w-full text-sm' : 'table table-sm mb-0' }}">
                                <thead><tr class="{{ $optical ? 'text-left text-xs text-slate-500' : 'small text-muted' }}"><th>Branch</th><th>Location (Google Maps)</th><th>WhatsApp</th></tr></thead>
                                <tbody>
                                    @foreach($branchLinks as $id => $row)
                                        <tr wire:key="branch-link-{{ $id }}">
                                            <td class="align-middle font-weight-bold" style="white-space:nowrap">{{ $row['name'] }}</td>
                                            <td>
                                                <input type="text" wire:model="branchLinks.{{ $id }}.map_link" maxlength="500" placeholder="Clinic's link"
                                                       class="{{ $optical ? 'ui-input' : 'form-control form-control-sm' }} @error('branchLinks.'.$id.'.map_link') is-invalid @enderror">
                                                @error('branchLinks.'.$id.'.map_link')<small class="{{ $optical ? 'text-xs text-red-600' : 'text-danger small' }}">{{ $message }}</small>@enderror
                                            </td>
                                            <td>
                                                <input type="text" wire:model="branchLinks.{{ $id }}.whatsapp_link" maxlength="500" placeholder="Clinic's WhatsApp"
                                                       class="{{ $optical ? 'ui-input' : 'form-control form-control-sm' }} @error('branchLinks.'.$id.'.whatsapp_link') is-invalid @enderror">
                                                @error('branchLinks.'.$id.'.whatsapp_link')<small class="{{ $optical ? 'text-xs text-red-600' : 'text-danger small' }}">{{ $message }}</small>@enderror
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                <button type="submit" class="{{ $optical ? 'ui-button ui-button-primary' : 'btn btn-primary btn-sm mt-3' }}">
                    <i class="fas fa-save"></i> Save links
                </button>
            </form>
        </div>
    </div>
</div>
