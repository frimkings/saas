<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Lists a new clinic can start with instead of typing them in: common eye diagnoses and the
 * item categories the cashier and inventory use. Offered when the platform onboards a business
 * whose plan includes the clinic; optical-only and attendance-only businesses don't get them.
 */
class ClinicStarterData
{
    public const DIAGNOSES = [
        'Acanthamoeba Keratitis', 'Accommodative Esotropia', 'Acute Angle Closure Glaucoma', 'Acute Anterior Uveitis (Iritis)',
        'Amblyopia (Anisometropic)', 'Amblyopia (Strabismic)', 'Anatomical Narrow Angles', 'Aphakia',
        'Arteritic Ischemic Optic Neuropathy (GCA)', 'Asteroid Hyalosis', 'Atopic Keratoconjunctivitis (AKC)', 'Bacterial Conjunctivitis',
        'Bacterial Keratitis (Pseudomonas)', 'Bacterial Keratitis (Staphylococcal)', 'Band Keratopathy', 'Behcet Disease (Ocular)',
        'Best Disease', 'Blepharitis', 'Branch Retinal Artery Occlusion (BRAO)', 'Branch Retinal Vein Occlusion (BRVO)',
        'Canaliculitis', 'Cataract', 'Central Retinal Artery Occlusion (CRAO)', 'Central Retinal Vein Occlusion (CRVO)',
        'Central Serous Chorioretinopathy (CSCR)', 'Chalazion', 'Choroidal Melanoma', 'Choroidal Nevus',
        'Chronic Angle Closure Glaucoma', 'Coats Disease', 'Congenital Cataract', 'Congenital Nystagmus',
        'Conjunctivitis', 'Contact Dermatoblepharitis', 'Corneal Abrasion', 'Corneal Laceration',
        'Cortical Cataract', 'Cystoid Macular Edema', 'Dacryocystitis', 'Dermatochalasis',
        'Diabetic Macular Edema (DME)', 'Dry Age-Related Macular Degeneration', 'Dry Eye (Evaporative/MGD)', 'Dry Eye (Sjogren Syndrome)',
        'Ectopia Lentis (Lens Subluxation)', 'Ectropion', 'Entropion', 'Epiretinal Membrane',
        'Episcleritis', 'Exotropia', 'Fourth Nerve Palsy', 'Fuchs Endothelial Dystrophy',
        'Fungal Keratitis (Candida)', 'Fungal Keratitis (Filamentous)', 'Giant Papillary Conjunctivitis (GPC)', 'Graft vs Host Disease (Ocular)',
        'Granular Corneal Dystrophy', 'Healthy Eyes', 'Herpes Simplex Keratitis (Epithelial)', 'Herpes Simplex Keratitis (Stromal)',
        'Herpes Zoster Ophthalmicus', 'Hordeolum', 'Horner Syndrome', 'Hyperopia',
        'Idiopathic Intracranial Hypertension (IIH)', 'Infantile Esotropia', 'Intermediate Uveitis (Pars Planitis)', 'Interstitial Keratitis',
        'Iridocorneal Endothelial (ICE) Syndrome', 'Juvenile Open Angle Glaucoma', 'Keratoconus', 'Keratoglobus',
        'Lattice Corneal Dystrophy', 'Macular Corneal Dystrophy', 'Macular Hole', 'Meibomian Gland Dysfunction (MGD)',
        'Mild Non-Proliferative Diabetic Retinopathy (NPDR)', 'Moderate Non-Proliferative Diabetic Retinopathy (NPDR)', 'Myasthenia Gravis (Ocular)', 'Myopia',
        'Neovascular Glaucoma', 'Neurotrophic Keratopathy', 'Non-Arteritic Ischemic Optic Neuropathy (NAION)', 'Normal Tension Glaucoma',
        'Nuclear Sclerotic Cataract', 'Ocular Cicatricial Pemphigoid', 'Ocular Hypertension', 'Ophthalmalgia',
        'Optic Atrophy', 'Optic Neuritis', 'Orbital Cellulitis', 'Orbital Floor Fracture (Blowout)',
        'Panuveitis', 'Papilledema', 'Pellucid Marginal Degeneration', 'Perennial Allergic Conjunctivitis (PAC)',
        'Persistent Fetal Vasculature (PFV)', 'Phlyctenular Keratoconjunctivitis', 'Pigmentary Glaucoma', 'Pinguecula',
        'Plateau Iris Syndrome', 'Posterior Capsule Opacification (PCO)', 'Posterior Subcapsular Cataract', 'Posterior Uveitis',
        'Posterior Vitreous Detachment (PVD)', 'Presbyopia', 'Preseptal Cellulitis', 'Primary Open Angle Glaucoma (POAG)',
        'Proliferative Diabetic Retinopathy (PDR)', 'Pseudoexfoliation Glaucoma', 'Pseudophakia', 'Pseudopterygium',
        'Pterygium', 'Ptosis (Involutional)', 'Punctate Epithelial Erosions', 'Recurrent Corneal Erosion',
        'Refractive Error', 'Retinal Detachment (Exudative)', 'Retinal Detachment (Rhegmatogenous)', 'Retinal Detachment (Tractional)',
        'Retinal Lattice Degeneration', 'Retinitis Pigmentosa', 'Retinoblastoma', 'Retinopathy of Prematurity (ROP)',
        'Retinoschisis', 'Sarcoidosis (Ocular)', 'Scleritis (Necrotizing)', 'Scleritis (Non-necrotizing)',
        'Seasonal Allergic Conjunctivitis (SAC)', 'Severe Non-Proliferative Diabetic Retinopathy (NPDR)', 'Sixth Nerve Palsy', 'Stargardt Disease',
        'Steroid-Induced Glaucoma', 'Stevens-Johnson Syndrome (Ocular)', 'Superior Limbic Keratoconjunctivitis (SLK)', 'Sympathetic Ophthalmia',
        'Terrien Marginal Degeneration', 'Third Nerve Palsy', 'Thyroid Eye Disease', 'Toxic Keratoconjunctivitis',
        'Toxoplasmosis Chorioretinitis', 'Traumatic Cataract', 'Trichiasis', 'Uveitic Glaucoma',
        'Vernal Keratoconjunctivitis (VKC)', 'Vitreous Hemorrhage', 'Wet Age-Related Macular Degeneration',
    ];

    /** Name => [type, description]. The type matters: Drugs is how the cashier knows an item is a drug. */
    public const CATEGORIES = [
        'Drugs' => ['drug', 'Eye drops, ointments and other medicines'],
        'Frames' => ['frame', 'Spectacle frames'],
        'Lenses' => ['lens', 'Spectacle and contact lenses'],
        'Services' => ['service', 'Consultations, tests and procedures'],
        'Others' => ['product', 'Anything that is not a drug, frame, lens or service'],
    ];

    /** Only businesses with the clinic are offered the lists. */
    public static function offeredFor(string $product): bool
    {
        return in_array($product, [PlanProduct::CLINIC, PlanProduct::BOTH], true);
    }

    /** Adds the chosen lists to a new clinic; returns how many of each were added. */
    public static function seed(int $clinicId, int $userId, bool $diagnoses, bool $categories): array
    {
        $now = now();
        if ($diagnoses) {
            DB::table('diagnoses')->insert(array_map(fn ($name) => ['clinic_id' => $clinicId, 'name' => $name, 'created_at' => $now, 'updated_at' => $now], self::DIAGNOSES));
        }
        if ($categories) {
            DB::table('categories')->insert(array_map(fn ($name, $c) => ['clinic_id' => $clinicId, 'user_id' => $userId, 'name' => $name, 'type' => $c[0], 'description' => $c[1], 'is_active' => true, 'created_at' => $now, 'updated_at' => $now], array_keys(self::CATEGORIES), self::CATEGORIES));
        }

        return ['diagnoses' => $diagnoses ? count(self::DIAGNOSES) : 0, 'categories' => $categories ? count(self::CATEGORIES) : 0];
    }
}
