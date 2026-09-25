<div wire:ignore.self class="modal fade" id="addRefractionModal">
    <div class="modal-dialog modal-lg" role="document"> <div class="modal-content">
            <form wire:submit="createRefraction">
                <div class="modal-header flex-column">
                    <div class="icon-box">
                        <i class="material-icons">&#xE5CD;</i>
                    </div>
                    <h4 class="modal-title w-100 text-center">SPECTACLE PRESCRIPTION</h4>
                    <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-12">
                            <table class="table table-bordered text-center table-sm">
                                <thead>
                                    <tr>
                                        <th>Eye</th>
                                        <th>Refraction (Rx)</th>
                                        <th>Distance VA</th>
                                        <th>ADD</th>
                                        <th>Near VA</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {{-- RIGHT EYE (OD) --}}
                                    <tr>
                                        <th scope="row">OD</th>
                                        <td>
                                            <input wire:model="state.refractionOD"
                                                class="form-control form-control-sm externals text-center" disabled>
                                        </td>
                                        <td>
                                            <input type="text" wire:model="state.refractionOD_distance_va"
                                                class="form-control form-control-sm text-center">
                                        </td>
                                        <td>
                                            <input type="text" wire:model="state.refractionOD_ADD"
                                                class="form-control form-control-sm text-center">
                                        </td>
                                        <td>
                                            <input type="text" wire:model="state.refractionOD_near_va"
                                                class="form-control form-control-sm text-center">
                                        </td>
                                    </tr>
                                    {{-- LEFT EYE (OS) --}}
                                    <tr>
                                        <th scope="row">OS</th>
                                        <td>
                                            <input wire:model="state.refractionOS"
                                                class="form-control form-control-sm externals text-center" disabled>
                                        </td>
                                        <td>
                                            <input type="text" wire:model="state.refractionOS_distance_va"
                                                class="form-control form-control-sm text-center">
                                        </td>
                                        <td>
                                            <input type="text" wire:model="state.refractionOS_ADD"
                                                class="form-control form-control-sm text-center">
                                        </td>
                                        <td>
                                            <input type="text" wire:model="state.refractionOS_near_va"
                                                class="form-control form-control-sm text-center">
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            
                            {{-- PD and Lens Type (Kept outside the Rx table for clarity) --}}
                            <table class="table table-bordered table-sm mt-3">
                                <tbody>
                                    <tr>
                                        <th style="width: 150px;">PD (Pupillary Distance)</th>
                                        <td>
                                            <input type="number" wire:model="state.pd"
                                                class="form-control form-control-sm externals text-center @error('pd') is-invalid @enderror">
                                            @error('pd') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                        </td>
                                    </tr>
                                    <tr>
                                        <th style="width: 150px;">Lens Type</th>
                                        <td>
                                            <select class="custom-select form-control @error('lensType') is-invalid @enderror"
                                                wire:model="state.lensType" required>
                                                <option>......</option>
                                                <option value="SV Photo, AR">SV Photo, AR</option>
                                                <option value="SV White">SV White</option>
                                                <option value="SV, Blue Block">SV, Blue Block</option>
                                                <option value="Bifocal, White ">Bifocal, White</option>
                                                <option value="Bifocal, Photo, AR">Bifocal, Photo, AR</option>
                                                <option value="Progressive Photo">Progressive Photo</option>
                                            </select>
                                            @error('lensType') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                        </div>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal"> <i
                            class="fa fa-times mr-1"></i>
                        Cancel</button>
                    <button type="submit" class="btn btn-danger"> <i
                            class="fa fa-save mr-1"></i>
                        Save</button>
                </div>
            </form>
        </div>
    </div>
</div>