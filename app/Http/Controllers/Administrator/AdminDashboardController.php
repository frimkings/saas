<?php

namespace App\Http\Controllers\Administrator;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Drugs;
class AdminDashboardController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request)
    {
        //
        // dd('here');
        return view('admin.admin-dashboard');

    }


    public function allDrugs () {

        $drugs = Drugs::all();
        return $drugs;

    }

    

}
