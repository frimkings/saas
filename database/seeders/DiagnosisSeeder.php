<?php

namespace Database\Seeders;

use App\Models\Diagnosis;
use Illuminate\Database\Seeder;

class DiagnosisSeeder extends Seeder
{
    public function run()
    {
        $diagnoses = [
            // --- OCULAR ALLERGY & IMMUNOLOGY ---
            'Seasonal Allergic Conjunctivitis (SAC)', 'Perennial Allergic Conjunctivitis (PAC)',
            'Vernal Keratoconjunctivitis (VKC)', 'Atopic Keratoconjunctivitis (AKC)', 
            'Giant Papillary Conjunctivitis (GPC)', 'Contact Dermatoblepharitis', 
            'Phlyctenular Keratoconjunctivitis', 'Superior Limbic Keratoconjunctivitis (SLK)', 
            'Toxic Keratoconjunctivitis', 'Ocular Cicatricial Pemphigoid', 
            'Stevens-Johnson Syndrome (Ocular)', 'Graft vs Host Disease (Ocular)',

            // --- CORNEA & ANTERIOR SEGMENT ---
            'Bacterial Keratitis (Pseudomonas)', 'Bacterial Keratitis (Staphylococcal)',
            'Fungal Keratitis (Filamentous)', 'Fungal Keratitis (Candida)', 'Acanthamoeba Keratitis', 
            'Herpes Simplex Keratitis (Epithelial)', 'Herpes Simplex Keratitis (Stromal)',
            'Herpes Zoster Ophthalmicus', 'Interstitual Keratitis', 'Fuchs Endothelial Dystrophy', 
            'Lattice Corneal Dystrophy', 'Granular Corneal Dystrophy', 'Macular Corneal Dystrophy',
            'Keratoconus', 'Keratoglobus', 'Pellucid Marginal Degeneration', 'Terrien Marginal Degeneration',
            'Pterygium', 'Pseudopterygium', 'Pinguecula', 'Dry Eye (Evaporative/MGD)', 
            'Dry Eye (Sjogren Syndrome)', 'Neurotrophic Keratopathy', 'Band Keratopathy',
            'Corneal Abrasion', 'Corneal Laceration', 'Recurrent Corneal Erosion', 

            // --- GLAUCOMA ---
            'Primary Open Angle Glaucoma (POAG)', 'Normal Tension Glaucoma', 
            'Acute Angle Closure Glaucoma', 'Chronic Angle Closure Glaucoma',
            'Pseudoexfoliation Glaucoma', 'Pigmentary Glaucoma', 'Neovascular Glaucoma',
            'Uveitic Glaucoma', 'Steroid-Induced Glaucoma', 'Ocular Hypertension', 
            'Anatomical Narrow Angles', 'Iridocorneal Endothelial (ICE) Syndrome',
            'Plateau Iris Syndrome', 'Juvenile Open Angle Glaucoma',

            // --- RETINA, CHOROID & VITREOUS ---
            'Mild Non-Proliferative Diabetic Retinopathy', 'Moderate NPDR', 'Severe NPDR', 
            'Proliferative Diabetic Retinopathy (PDR)', 'Diabetic Macular Edema (DME)', 
            'Dry Age-Related Macular Degeneration', 'Wet Age-Related Macular Degeneration', 
            'Retinal Detachment (Rhegmatogenous)', 'Retinal Detachment (Exudative)', 
            'Retinal Detachment (Tractional)', 'Retinoschisis', 'Central Retinal Vein Occlusion (CRVO)', 
            'Branch Retinal Vein Occlusion (BRVO)', 'Central Retinal Artery Occlusion (CRAO)', 
            'Branch Retinal Artery Occlusion (BRAO)', 'Cystoid Macular Edema', 
            'Central Serous Chorioretinopathy (CSCR)', 'Macular Hole', 'Epiretinal Membrane',
            'Retinitis Pigmentosa', 'Stargardt Disease', 'Best Disease', 'Choroidal Nevus', 
            'Choroidal Melanoma', 'Vitreous Hemorrhage', 'Asteroid Hyalosis',
            'Posterior Vitreous Detachment (PVD)', 'Retinal Lattice Degeneration',

            // --- CATARACT & LENS ---
            'Nuclear Sclerotic Cataract', 'Cortical Cataract', 'Posterior Subcapsular Cataract',
            'Congenital Cataract', 'Traumatic Cataract', 'Posterior Capsule Opacification (PCO)',
            'Ectopia Lentis (Lens Subluxation)', 'Aphakia', 'Pseudophakia',

            // --- UVEITIS & INFLAMMATION ---
            'Acute Anterior Uveitis (Iritis)', 'Intermediate Uveitis (Pars Planitis)', 
            'Posterior Uveitis', 'Panuveitis', 'Scleritis (Necrotizing)', 
            'Scleritis (Non-necrotizing)', 'Episcleritis', 'Sympathetic Ophthalmia', 
            'Behcet Disease (Ocular)', 'Sarcoidosis (Ocular)', 'Toxoplasmosis Chorioretinitis',

            // --- NEURO-OPHTHALMOLOGY ---
            'Optic Neuritis', 'Non-Arteritic Ischemic Optic Neuropathy (NAION)', 
            'Arteritic Ischemic Optic Neuropathy (GCA)', 'Papilledema', 'Optic Atrophy', 
            'Horner Syndrome', 'Third Nerve Palsy', 'Fourth Nerve Palsy', 'Sixth Nerve Palsy', 
            'Myasthenia Gravis (Ocular)', 'Idiopathic Intracranial Hypertension (IIH)',

            // --- OCULOPLASTICS & ORBIT ---
            'Blepharitis', 'Meibomian Gland Dysfunction (MGD)', 'Chalazion', 'Hordeolum',
            'Ectropion', 'Entropion', 'Trichiasis', 'Ptosis (Involutional)', 
            'Dermatochalasis', 'Orbital Cellulitis', 'Preseptal Cellulitis', 
            'Thyroid Eye Disease', 'Dacryocystitis', 'Canaliculitis', 'Orbital Floor Fracture (Blowout)',

            // --- PEDIATRICS & STRABISMUS ---
            'Amblyopia (Strabismic)', 'Amblyopia (Anisometropic)', 'Infantile Esotropia', 
            'Accommodative Esotropia', 'Exotropia', 'Congenital Nystagmus', 
            'Retinopathy of Prematurity (ROP)', 'Retinoblastoma', 'Coats Disease',
            'Persistent Fetal Vasculature (PFV)'
        ];

        foreach ($diagnoses as $name) {
            Diagnosis::firstOrCreate(['name' => $name]);
        }
    }
}