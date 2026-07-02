<?php

namespace App\Http\Controllers\Escrow\Backend;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Repositories\ImageUtils;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BackendController extends Controller
{
    protected ImageUtils $imageUtil;

    public function __construct(ImageUtils $imageUtil)
    {
        $this->imageUtil = $imageUtil;
    }

    public function getDashboard()
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return redirect()->route('escrow.login')->with('message', 'You have to login before accessing the resource.');
            }

            if ($user->super_admin == 1) {
                $transactions = Transaction::orderBy('id', 'Desc')->paginate(30);
                $trans = Transaction::orderBy('id', 'Desc');
            } else {
                $transactions = Transaction::where('appid', $user->app_id)->orderBy('id', 'Desc')->paginate(30);
                $trans = Transaction::where('appid', $user->app_id)->orderBy('id', 'Desc');
            }

            $data['dashboardActive'] = 'active';
            $data['pageTitle'] = 'Dashboard';
            $data['transactions'] = $transactions;
            $data['total_tranx'] = $trans->count();
            $data['total_fulfilled_tranx'] = $trans->where('trans_status', 'Fulfilled')->count();
            $data['tranx_sum'] = $trans->sum('amount');
            $data['tranx_month_sum'] = $trans->whereBetween('posting_date', [
                Carbon::now()->startOfMonth(),
                Carbon::now()->endOfMonth(),
            ])->sum('amount');

            return view('backend.dashboard', $data);
        } catch (Exception $e) {
            return redirect()->back()->with('message', $e->getMessage());
        }
    }

    public function getReportPage()
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return redirect()->route('escrow.login')->with('message', 'You have to login before accessing the resource.');
            }

            if ($user->super_admin == 1) {
                $transactions = Transaction::orderBy('id', 'Desc')->paginate(30);
                $trans = Transaction::orderBy('id', 'Desc');
            } else {
                $transactions = Transaction::where('appid', $user->app_id)->orderBy('id', 'Desc')->paginate(30);
                $trans = Transaction::where('appid', $user->app_id)->orderBy('id', 'Desc');
            }

            $data['transactions'] = $transactions;
            $data['total_tranx'] = $trans->count();
            $data['total_fulfilled_tranx'] = $trans->where('trans_status', 'Fulfilled')->count();
            $data['tranx_sum'] = $trans->sum('amount');
            $data['tranx_month_sum'] = $trans->whereBetween('posting_date', [
                Carbon::now()->startOfMonth(),
                Carbon::now()->endOfMonth(),
            ])->sum('amount');
            $data['pageTitle'] = 'Reports';
            $data['reportActive'] = 'active';

            return view('backend.reports', $data);
        } catch (Exception $e) {
            return redirect()->back();
        }
    }
}
