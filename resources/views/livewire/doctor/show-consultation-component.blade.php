<div data-livewire-root>
<div class="container-fluid mt-3  col-12">
    {{-- <div class="mb-3">
        <button wire:click.prevent="openAddSpectaclePrescriptionModal" class="btn btn-primary float-right">
            <i class="fa fa-plus-circle mr-1"></i>Add SRX</button>
    </div> --}}
    <style>
        .state {
            text-align: center;
        }

        .externals,
        .lens-order {
            width: 100%;
            text-align: center;
        }
    </style>
    <div class="row mr-2">

        <div class="col-12 col-md-6">
            <demographics>

                <div class="row">
                    <div class="col-12 col-sm-6">
                        <div class="form-group row">
                            {{-- <div class="col-sm-12">
                                <input type="text" class="form-control form-control-sm" id="colFormLabelSm"
                                    placeholder="col-form-label-sm" value="{{ auth()->user()->name }}" disabled>
                            </div> --}}
                            <div class="col-sm-12">
                                <input type="text" class="form-control form-control-sm" id="colFormLabelSm" {{--
                                    placeholder="col-form-label-sm" value="{{ $demographics->patient->name }}" --}}
                                    disabled>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <div class="form-group row">
                            <div class="col-sm-12">
                                <input type="text" class="form-control form-control-sm" id="colFormLabelSm"
                                    placeholder="col-form-label-sm" value="26" disabled>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12 col-sm-6">
                        <div class="form-group row">
                            <div class="col-sm-12">
                                <input type="text" class="form-control form-control-sm" id="colFormLabelSm" {{--
                                    placeholder="col-form-label-sm" value="{{ $demographics->patient->gender }}" --}}
                                    disabled>
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-group row">
                            <div class="col-sm-12">
                                <input type="text" class="form-control form-control-sm" id="colFormLabelSm" {{--
                                    placeholder="col-form-label-sm" value="{{ $demographics->patient->occupation }}"
                                    --}} disabled>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12 col-sm-6">
                        <div class="form-group row">
                            <div class="col-sm-12">
                                <input type="text" class="form-control form-control-sm" id="colFormLabelSm" {{--
                                    placeholder="col-form-label-sm" value="{{ $demographics->patient->address }}" --}}
                                    disabled>
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-group row">
                            <div class="col-sm-12">
                                <input type="text" class="form-control form-control-sm" id="colFormLabelSm" {{--
                                    placeholder="col-form-label-sm" value="{{ $demographics->patient->pxnumber }}" --}}
                                    disabled>
                            </div>
                        </div>
                    </div>
                </div>
            </demographics>
            {{-- history --}}
            <history>
                <div class="row">
                    <div class="form-floating col-12">
                        <textarea wire:model="state.chiefComplaint" class="form-control"
                            class="form-control @error('chiefComplaint') is-invalid @enderror" id="chiefComplaint"
                            placeholder="Leave a comment here" id="CC"> @error('chiefComplaint')
                                <div class="invalid-feedback">
                                    {{ $message }}
                                </div>
                                @enderror
                            </textarea>
                        <label for="CC" class="col-sm-12 col-form-label">.. Chief Complaint</label>

                    </div>
                </div>

                {{-- end history --}}

                <div wire:ignore class="row">
                    <div class="col-12">
                        <label for="odq" class="col-sm-12 col-form-label row ">Direct Questions</label>
                        <select class="odq col-sm-12 col-form-label" wire:model="state.odq" multiple="multiple">
                            <option value="discharges">discharges</option>
                            <option value="tearing">tearing</option>
                            <option value="photophobia">photophobia</option>
                            <option value="headaches">headaches</option>
                            <option value="burning sensation">burning sensation</option>
                            <option value="FB sensation">FB sensation</option>
                            <option value="redness">redness</option>
                            <option value="diplopia">diplopia</option>

                        </select>
                    </div>
                </div>
                <div class="row">
                    <div class="form-floating mt-3 col-12">
                        <textarea wire:model="state.others" class="form-control"
                            placeholder="Leave a comment here" id="others"></textarea>
                        <label for="others" class="col-sm-12 col-form-label">..Others</label>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12 state mt-3">
                        <div class="table-responsive">
                        <table class="table table-hover table-bordered">
                            <style>
                                .visualAcuity {
                                    width: 50%;
                                }
                            </style>
                            <p class="text-center mt-1">Visual Acuity (Unaided)</p>

                            <thead>
                                <tr>
                                    <th scope="col">#</th>
                                    <th scope="col">@6m</th>
                                    <th scope="col">@0.4m</th>
                                    <th scope="col">PH</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <th scope="row">0D</th>
                                    <td><input wire:model="state.vaOD6m" type="text"
                                            class=" form-control form-control-sm visualAcuity" list="distance_va">1
                                    </td>
                                    <td><input wire:model="state.vaOD4m" type="text"
                                            class="form-control form-control-sm visualAcuity" list="near_va">2
                                    </td>
                                    <td><input wire:model="state.phOD6m" type="text"
                                            class="form-control form-control-sm visualAcuity" list="distance_va">3
                                    </td>


                                </tr>
                                <tr>
                                    <th scope="row">OS</th>
                                    <td><input wire:model="state.vaOS6m" type="text"
                                            class=" form-control form-control-sm visualAcuity" list="distance_va">4
                                    </td>
                                    <td><input wire:model="state.vaOS4m" type="text"
                                            class="form-control form-control-sm visualAcuity" list="near_va">5
                                    </td>
                                    <td><input wire:model="state.phOS6m" type="text"
                                            class="form-control form-control-sm visualAcuity" list="distance_va">6
                                    </td>
                                </tr>

                            </tbody>
                        </table>
                        </div>{{-- /table-responsive --}}
                    </div>
                </div>

                <div class="row">
                    <div class="col-12 state mt-2">
                        <div class="table-responsive">
                        <table class="table table-hover table-bordered">
                            <style>
                                /* .currentSRX {
                        width: 50%;
                    } */
                            </style>
                            <p class="text-center">Current SRX</p>
                            <thead>
                                <tr>
                                    <th scope="col">OD</th>
                                    <th scope="col">OS</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><input wire:model="state.currentSrxOD" type="text"
                                            class=" form-control form-control-sm currentSRX" list="">
                                    </td>
                                    <td><input wire:model="state.currentSrxOS" type="text"
                                            class=" form-control form-control-sm currentSRX">
                                    </td>


                                </tr>


                            </tbody>
                        </table>
                        </div>{{-- /table-responsive --}}
                    </div>
                </div>
                <div wire:ignore class="row">
                    <div class="col-12">
                        <label for="diagnosis" class="col-sm-12 col-form-label  ">Diagnosis</label>
                        <select class="diagnosis col-sm-12 col-form-label" wire.model="state.diagnosis"
                            multiple="multiple">
                            <option value="Refractive Error">Refractive Error</option>
                            <option value="Dry Eye Syndrome">Dry Eye Syndrome</option>
                            <option value="Bacterial Conjunctivitis">Bacterial Conjunctivitis</option>
                        </select>
                    </div>
                </div>
                <div class="row">
                    <div class="form-floating mt-3 col-12">
                        <textarea wire:model="state.notes" class="form-control" id="notes"></textarea>
                        <label for="notes" class="col-sm-12 col-form-label">..Notes</label>
                    </div>
                </div>
            </history>
        </div>
        <div class="col-12 col-md-6">
            <div class="row">
                <div class="col-12 mt-0">
                    <div class="table-responsive">
                    <table class="table table-hover table-bordered border-primary">
                        <style>
                            .externals {
                                width: 100%;
                                text-align: center;
                            }
                        </style>

                        <thead>
                            {{-- <td colspan="3">Externals and Internals</td> --}}
                            <td colspan="3">
                                <label for="" class="col-sm-12 col-form-label  ">Externals and Internals</label>
                                <div class="footer">
                                    <button type="#" class="btn btn-success  float-right  btn btn-block"> <i
                                            class="fa fa-save mr-2"><br><span>Edit Record</span></i>

                                </div>
                            </td>
                            <tr>
                                <th scope="col">Structure</th>
                                <th scope="col">OD</th>
                                <th scope="col">OS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <th scope="row">Lids</th>
                                <td><input wire:model="state.lidsOD" type="text"
                                        class=" form-control form-control-sm externals" list="distance"></td>
                                <td><input wire:model="state.lidsOS" type="text"
                                        class="form-control form-control-sm externals" list="nea">
                                </td>


                            </tr>
                            <tr>
                                <th scope="row">Conjunctiva</th>
                                <td><input wire:model="state.conjunctivaOD" type="text"
                                        class=" form-control form-control-sm externals" list="distance"></td>
                                <td><input wire:model="state.conjunctivaOS" type="text"
                                        class="form-control form-control-sm externals" list="near">
                                </td>

                            </tr>
                            <tr>
                                <th scope="row">Cornea</th>
                                <td><input wire:model="state.corneaOD" type="text"
                                        class=" form-control form-control-sm externals" list="distance"></td>
                                <td><input wire:model="state.corneaOS" type="text"
                                        class="form-control form-control-sm externals" list="near">
                                </td>

                            </tr>
                            <tr>
                                <th scope="row">Iris</th>
                                <td><input wire:model="state.irisOD" type="text"
                                        class=" form-control form-control-sm externals" list="distance"></td>
                                <td><input wire:model="state.irisOS" type="text"
                                        class="form-control form-control-sm externals" list="near">
                                </td>

                            </tr>
                            <tr>
                                <th scope="row">Pupil</th>
                                <td><input wire:model="state.pupilOD" type="text"
                                        class=" form-control form-control-sm externals" list="distance"></td>
                                <td><input wire:model="state.pupilOS" type="text"
                                        class="form-control form-control-sm externals" list="near">
                                </td>

                            </tr>
                            <tr>
                                <th scope="row">Anterior chamber</th>
                                <td><input wire:model="state.acOD" type="text"
                                        class="form-control form-control-sm externals" list="distance"></td>
                                <td><input wire:model="state.acOS" type="text"
                                        class="form-control form-control-sm externals" list="near"></td>
                            </tr>
                            <tr>
                                <th scope="row">Lens</th>
                                <td><input wire:model="state.lensOD" type="text"
                                        class=" form-control form-control-sm externals" list="distance"></td>
                                <td><input wire:model="state.lensOS" type="text"
                                        class="form-control form-control-sm externals" list="near">
                                </td>

                            </tr>
                            <tr>
                                <th scope="row">Vitreous</th>
                                <td><input wire:model="state.vitreousOD" type="text"
                                        class=" form-control form-control-sm externals" list="distance"></td>
                                <td><input wire:model="state.vitreousOS" type="text"
                                        class="form-control form-control-sm externals" list="near">
                                </td>

                            </tr>
                            <tr>
                                <th scope="row">Fundus</th>
                                <td><input wire:model="state.fundusOD" type="text"
                                        class=" form-control form-control-sm externals" list="distance"></td>
                                <td><input wire:model="state.fundusOS" type="text"
                                        class="form-control form-control-sm externals" list="near">
                                </td>

                            </tr>
                            <tr>
                                <th scope="row">CDR</th>
                                <td><input wire:model="state.cdrOD" type="text"
                                        class=" form-control form-control-sm externals" list="distance"></td>
                                <td><input wire:model="state.cdrOS" type="text"
                                        class="form-control form-control-sm externals" list="near">
                                </td>

                            </tr>
                            <tr>
                                <th scope="row">Macula</th>
                                <td><input wire:model="state.maculaOD" type="text"
                                        class=" form-control form-control-sm externals" list="distance"></td>
                                <td><input wire:model="state.maculaOS" type="text"
                                        class="form-control form-control-sm externals" list="near">
                                </td>

                            </tr>
                            <tr>
                                <th scope="row">Periphery</th>
                                <td><input wire:model="state.peripheryOD" type="text"
                                        class=" form-control form-control-sm externals" list="distance"></td>
                                <td><input wire:model="state.peripheryOS" type="text"
                                        class="form-control form-control-sm externals">
                                </td>

                            </tr>
                            <tr>
                                <th scope="row">IOP</th>
                                <td><input wire:model="state.IOPOD" type="number"
                                        class=" form-control form-control-sm externals"></td>
                                <td><input wire:model="state.IOPOS" type="number"
                                        class="form-control form-control-sm externals">
                                </td>

                            </tr>

                            <tr class="table-info" colspan="2">
                                <td colspan="3">Prescriptions
                                    <button type="button" class="btn btn-info float-right" data-toggle="modal"
                                        data-target="#modal-info">
                                        SRX
                                    </button>
                                </td>
                            </tr>





                        </tbody>
                    </table>
                    </div>{{-- /table-responsive --}}
                </div>
            </div>
            <div class="row">
                <div class="col-12">
                <div class="table-responsive">
                <table class="table table-hover table-bordered border-secondary">
                    <tr>
                        <th rowspan="2">Drug Name</th>
                        <th colspan="3">Dosage</th>
                    </tr>
                    <tr>
                        <td>eye</td>
                        <td>freq</td>
                        <td>qty</td>

                    </tr>
                    <tr>
                        <td>Olopatadine</td>
                        <td>BE</td>
                        <td>tid</td>
                        <td>1</td>


                    </tr>

                </table>
                </div>{{-- /table-responsive --}}
                </div>
                <div class="col-12 mt-2">
                    <button type="submit" class="btn btn-success btn-block">
                        <i class="fa fa-save mr-2"></i>Edit Record
                    </button>
                </div>
            </div>






        </div>
        {{-- end second column --}}



    </div>




    {{-- modal --}}
    <form autocomplete="off" wire:submit="addSpectaclePrescription">
        <div class="modal fade" id="mdodal-info" wire.ignore.self>
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content bg-info">

                    <div class="modal-body">
                        <div class="row">
                            <div class="col-12">
                                <div class="table-responsive">
                                <table class="table table-hover table-bordered border-info">
                                    <thead>
                                        <td colspan="7">
                                            <label for="" class="col-sm-12 col-form-label  ">
                                                <div class="row">
                                                    <button type="button" class="close float-xl-right btn-danger"
                                                        data-dismiss="modal" aria-label="Close">
                                                        <span aria-hidden="true">&times;</span>
                                                    </button>
                                                    <div class="col-12">
                                                        <h5>
                                                            <p class="text-center">{{ $appSettings->clinic_name ?? \App\Models\Setting::DEFAULT_CLINIC_NAME }}
                                                            </p>
                                                        </h5>
                                                        <small>
                                                            <p class="text-center">0266457979</p>
                                                        </small>
                                                    </div>
                                                </div>
                                                <div class="row">
                                                    <div class="col-12">
                                                        <p class="text-center">Name: {{ auth()->user()->name }} ||
                                                            <span> Age: 27 years</span>
                                                        </p>

                                                    </div>
                                                    <div class="col-12">
                                                        <p class="text-center">Gender : Male ||
                                                            <span> Date:
                                                                12/02/2022</span>
                                                        </p>
                                                    </div>
                                                </div>
                                            </label>
                                        </td>
                                        <tr>
                                            <th scope="col"></th>
                                            <th scope="col">SPH</th>
                                            <th scope="col">CYL</th>
                                            <th scope="col">AXIS</th>
                                            <th scope="col">VA</th>
                                            <th scope="col">ADD</th>
                                            <th scope="col">VA</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <th scope="row">OD</th>
                                            <td><input wire:model="specs.distanceOdSphere" type="text"
                                                    class="form-control form-control-sm externals" list="nea">
                                            </td>

                                            <td><input wire:model="specs.distanceOdCyl" type="text"
                                                    class="form-control form-control-sm externals" list="nea">
                                            </td>
                                            <td><input wire:model="specs.distanceOdAxis" type="text"
                                                    class=" form-control form-control-sm externals" list="distance">
                                            </td>
                                            <td><input wire:model="specs.distanceOdVa" type="text"
                                                    class=" form-control form-control-sm externals" list="distance">
                                            </td>

                                            <td><input wire:model="specs.addOd" type="text"
                                                    class=" form-control form-control-sm externals" list="distance">
                                            </td>
                                            <td><input wire:model="specs.addOdVa" type="text"
                                                    class="form-control form-control-sm externals" list="nea">
                                            </td>
                                        </tr>
                                        <th scope="row">OS</th>
                                        <td><input wire:model="specs.distanceOsSphere" type="text"
                                                class="form-control form-control-sm externals" list="nea">
                                        </td>

                                        <td><input wire:model="specs.distanceOsCyl" type="text"
                                                class="form-control form-control-sm externals" list="nea">
                                        </td>
                                        <td><input wire:model="specs.distanceOsAxis" type="text"
                                                class=" form-control form-control-sm externals" list="distance">
                                        </td>
                                        <td><input wire:model="specs.distanceOsVa" type="text"
                                                class=" form-control form-control-sm externals" list="distance">
                                        </td>

                                        <td><input wire:model="specs.addOs" type="text"
                                                class=" form-control form-control-sm externals" list="distance">
                                        </td>
                                        <td><input wire:model="specs.addOsVa" type="text"
                                                class="form-control form-control-sm externals" list="nea">
                                        </td>
                                    </tbody>
                                </table>
                                </div>{{-- /table-responsive --}}
                            </div>
                        </div>
                        <div class="row lens-order">
                            <div class="col-12 col-sm-4">
                                <label for="name">PD</label>
                                <div class="form-group">
                                    <input type="number" class="form-control" value="588-12-22">
                                </div>
                            </div>
                            <div class="col-12 col-sm-4">
                                <label for="lensType">Lens Type</label>
                                <select class="custom-select" id="lensType" wire:model="specs.lensType"
                                    class="form-control @error('lensType') is-invalid @enderror" id="lensType" required>
                                    <option>......</option>
                                    <option value="SV Photo, AR">SV Photo, AR</option>
                                    <option value="SV White">SV White</option>
                                    <option value="SV, Blue Block">SV, Blue Block</option>
                                    <option value="Bifocal, White ">Bifocal, White</option>
                                    <option value="Bifocal, Photo, AR">Bifocal, Photo, AR</option>
                                    <option value="Progressive Photo">Progressive Photo</option>
                                </select>
                            </div>
                            <div class="col-12 col-sm-4">
                                <label for="name">Other Specifications</label>
                                <div class="form-group">
                                    <input type="text" class="form-control">
                                </div>
                            </div>

                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal"><i
                                        class="fa fa-times mr-1"></i> Cancel</button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fa fa-save mr-1"></i>

                                    <span>Save Record</span>
                                </button>
                            </div>
                        </div>

                    </div>

                </div>
                <!-- /.modal-content -->
            </div>
            <!-- /.modal-dialog -->
        </div>


        <!-- /.modal -->

        {{-- end modal --}}
    </form>



    <div id="modal-info" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="confirmationModalLabel"
        aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-confirm">
            <div class="modal-content">
                <div class="modal-header flex-column">
                    <div class="icon-box">
                        <i class="material-icons">&#xE5CD;</i>
                    </div>

                    <h4 class="modal-title w-100">Are you sure?</h4>
                    <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="col-12">
                        <div class="form-group">
                            <label for="gender">Status</label>
                            <select class="custom-select" id="gender2" wire:model="specs.paymentStatus"
                                class="form-control @error('gender') is-invalid @enderror" id="gender"
                                placeholder="gender">
                                <option>......</option>
                                <option value="Paid">Paid</option>
                                {{-- <option value="Unpaid">Female</option> --}}
                            </select>
                            @error('gender')
                            <div class="invalid-feedback">
                                {{ $message }}
                            </div>
                            @enderror
                        </div>

                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal"> <i
                            class="fa fa-times mr-1"></i>
                        Cancel</button>
                    <button type="button" wire:click.prevent="addSpectaclePrescription" class="btn btn-danger"> <i
                            class="fa fa-save mr-1"></i>
                        Save</button>
                </div>
            </div>
        </div>
    </div>



    <datalist id="distance_va">
        <option value="6/60">
        <option value="6/48">
        <option value="6/30">
        <option value="6/20">
        <option value="6/15">
        <option value="6/12">
        <option value="6/9">
        <option value="6/6">
        <option value="6/4.5">
        <option value="6/3">
        <option value="CF@1">
        <option value="CF@2">
        <option value="CF@3">
        <option value="6/48">
        <option value="6/48">
    </datalist>
    <datalist id="near_va">
        <option value="N4">
        <option value="N5">
        <option value="N6">
        <option value="N7">
        <option value="N8">
        <option value="N10">

    </datalist>
    <script>
        $(document).ready(function() {
    $('.diagnosis').select2(
        {

            tags: true,
    tokenSeparators: [',', ' '],
    theme: "classic",

        }
    ).on('change',function () {
        @this.set('state.diagnosis', $(this).val());
      });
    ;
});


    </script>
    <script>
        $(document).ready(function() {
    $('.odq').select2(
        {

            tags: true,
    tokenSeparators: [',', ' '],
    // theme: "bootstrap",
    theme: "classic",
        }
    ).on('change',function () {
        @this.set('state.odq', $(this).val());
      });
});
    </script>
</div>
