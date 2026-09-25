<?php

namespace App\Livewire\Doctor;

use Livewire\Component;
use App\Models\Consultations;
use App\Models\CashierPatientClearance;
use Illuminate\Support\Facades\Validator;
use App\Models\Refractions;
use Illuminate\Support\Facades\Auth;


class UpdateConsultationComponent extends Component
{
    public $state = [];
    public $specs = [];
    public $demographics;
    public $consulation;

    protected $listeners = ['addSpectaclePrescription' => 'addSpectaclePrescription'];
    public function mount(Consultations $consultation)
    {
        // dd($consultation);
        $this->state = $consultation->toArray();
        $this->consultation = $consultation;

    }

    // public function mount( $id)
    // {
    //     // dd($consultation);
    //     // $this->demographics = CashierPatientClearance::findOrFail($id);
    //     $this->state = Consultations::findOrFail($id)->toArray;
    //     // $this->state = $consultation->toArray();
    //     //  dd($this->demographics['id']);
    //     // $this -> state = $consultations ->toArray();
    //     // dd($this->state);
    // }




    public function addSpectaclePrescription()
    {
        //    dd('here');
        // dd($this->specs);


        // $id =  $this->demographics['id'];
        $validatedData = Validator::make($this->state, [
            'patient_id' => 'required',
            'distanceOdSphere' => 'nullable',
            'distanceOdCyl' => 'nullable',
            'distanceOdAxis' => 'nullable',
            'distanceOdVa' => 'nullable',
            'addOd' => 'nullable',
            'addOdVa' => 'nullable',
            'distanceOsSphere' => 'nullable',
            'distanceOsCyl' => 'nullable',
            'distanceOsAxis' => 'nullable',
            'distanceOsVa' => 'nullable',
            'addOs' => 'nullable',
            'addOsVa' => 'nullable',
            'pd' => 'required',
            'lensType' => 'required',
            'otherSpecs' => 'required',
        ])->validate();

        // $validatedData['clearance_id'] = $id;
        $validatedData['user_id'] = Auth::user()->id;

        Refractions::create($validatedData);


        $this->dispatch('hide-addPatientModal-form', ...['message' => 'Patient added']);
        // return back();
    }





    public function updateConsulatation(Consultations $consultations)
    {
        // $id =  $this->demographics['id'];
        $validatedData = Validator::make($this->state, [
            'chiefComplaint' => 'required',
            'others' => 'nullable',
            'odq' => 'nullable',
            'vaOD6m' => 'nullable',
            'vaOS6m' => 'nullable',
            'vaOD4m' => 'nullable',
            'vaOS4m' => 'nullable',
            'phOD6m' => 'nullable',
            'phOS6m' => 'nullable',
            'currentSrxOD' => 'nullable',
            'currentSrxOS' => 'nullable',
            'lidsOD' => 'nullable',
            'lidsOS' => 'nullable',
            'conjunctivaOD' => 'nullable',
            'conjunctivaOS' => 'nullable',
            'corneaOD' => 'nullable',
            'corneaOS' => 'nullable',
            'acOD' => 'nullable',
            'acOS' => 'nullable',
            'irisOD' => 'nullable',
            'irisOS' => 'nullable',
            'pupilOD' => 'nullable',
            'pupilOS' => 'nullable',
            'lensOD' => 'nullable',
            'lensOS' => 'nullable',
            'vitreousOD' => 'nullable',
            'vitreousOS' => 'nullable',
            'fundusOD' => 'nullable',
            'fundusOS' => 'nullable',
            'cdrOD' => 'nullable',
            'cdrOS' => 'nullable',
            'IOPOD' => 'nullable',
            'IOPOS' => 'nullable',
            'maculaOD' => 'nullable',
            'maculaOS' => 'nullable',
            'peripheryOD' => 'nullable',
            'peripheryOS' => 'nullable',
            'notes' => 'nullable',
            'review' => 'nullable',

        ])->validate();

        // $validatedData['clearance_id'] = $id;
        $validatedData['user_id'] = Auth::user()->id;
        // dd($validatedData);
        // $consultations = Consultations::findOrFail($id);

        $this->consultation->update($validatedData);

        // return redirect()->route('doctor.edit-consultation', $consultations->id);

        $this->dispatch('hide-addPatientModal-form', ...['message' => 'Records Updated']);



        //    $updateClearanceStatus = CashierPatientClearance::findOrFail($this->demographics['id']);
        // $changeStatus = CashierPatientClearance::where('id', $id)->where('status', 'Unpaid');
        // $this->status->update($changeStatus);
        // $this->dispatch('hide-addPatientModal-form', ...['message' => 'Updated']);



        // return 'saved';
    }







    public function render()
    {
        return view('livewire.doctor.update-consultation-component')
            ->layout('layouts.doctor.doctor-layout');
    }
}
