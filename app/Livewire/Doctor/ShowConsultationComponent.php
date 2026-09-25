<?php

namespace App\Livewire\Doctor;

use App\Models\Consultations;
use Livewire\Component;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use App\Models\Refractions;


class ShowConsultationComponent extends Component
{

    public $state = [];
    public $consultation;
    public $specs = [];
    public function mount(Consultations $consultation)
    {
        $this->state = $consultation->toArray();
        $this->consultation = $consultation;
    }






    public function addSpectaclePrescription()
    {
        //    dd('here');
        // dd($this->specs);


        // $id =  $this->demographics['id'];
        $validatedData = Validator::make($this->specs, [
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
        //    dd($validatedData);
        Refractions::create($validatedData);


        $this->dispatch('hide-addPatientModal-form', ...['message' => 'Patient added']);
        // return back();
    }


    public function render()
    {
        return view('livewire.doctor.show-consultation-component')
            ->layout('layouts.doctor.doctor-layout');
    }
}
