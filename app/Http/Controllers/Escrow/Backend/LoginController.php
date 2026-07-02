<?php

namespace App\Http\Controllers\Escrow\Backend;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class LoginController extends Controller
{
    public function getLoginPage()
    {
        return view('backend.login');
    }

    public function postLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'email|required',
            'password' => 'required|min:4',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        if (Auth::attempt(['email' => $request->input('email'), 'password' => $request->input('password')])) {
            $user = Auth::user();

            if ($user->status == 0) {
                Auth::logout();
                return redirect()->back()->with('message', 'Your account is inactive!');
            }

            return redirect()->route('escrow.dashboard')->with('message', 'You are logged in!');
        }

        return redirect()->back()->with('message', 'Your login detail is incorrect!');
    }

    public function logout()
    {
        Auth::logout();
        return redirect()->route('escrow.login');
    }

    public function getUsers()
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return redirect()->route('escrow.login')->with('message', 'You have to login before accessing the resource.');
            }

            if ($user->super_admin == 1) {
                $data['users'] = User::orderBy('firstName', 'asc')->paginate(30);
                $data['client_apps'] = ApiToken::all();
            } else {
                $data['users'] = User::where('app_id', $user->app_id)->orderBy('firstName', 'asc')->paginate(30);
                $data['client_apps'] = ApiToken::where('app_id', $user->app_id)->get();
            }

            $data['userActive'] = 'active-menu';
            return view('backend.user_mgt', $data);
        } catch (Exception $e) {
            return redirect()->back()->with('message', $e->getMessage());
        }
    }

    public function addUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'email|required|unique:users',
            'password' => 'string|required|min:4',
            'lname' => 'string|required',
            'fname' => 'string|required',
            'job_title' => 'string|required',
            'phone_no' => 'numeric|nullable',
            'is_super_admin' => 'string|required',
            'app_id' => 'integer|required',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        try {
            $user = Auth::user();
            if (!$user) {
                return redirect()->route('escrow.login')->with('message', 'You have to login before accessing the resource.');
            }

            User::create([
                "email" => $request->input('email'),
                "name" => $request->input('fname') . ' ' . $request->input('lname'),
                "password" => Hash::make($request->input('password')),
                "firstName" => $request->input('fname'),
                "lastName" => $request->input('lname'),
                "job_title" => $request->input('job_title'),
                "phoneNo" => $request->input('phone_no'),
                "account_type" => $request->input('is_super_admin'),
                "app_id" => $request->input('app_id'),
            ]);

            return redirect()->route('escrow.users')->with('message', 'User account was created successfully.');
        } catch (Exception $e) {
            return redirect()->back()->with('message', $e->getMessage() . ' - Something went wrong.');
        }
    }

    public function editUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'email|required',
            'password' => 'string|nullable',
            'lname' => 'string|required',
            'fname' => 'string|required',
            'job_title' => 'string|required',
            'phone_no' => 'numeric|nullable',
            'is_super_admin' => 'string|required',
            'userID' => 'integer|required',
            'app_id' => 'integer|required',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        try {
            $userUpdate = User::find($request->input('userID'));
            if ($userUpdate) {
                $userUpdate->update([
                    "email" => $request->input('email'),
                    "name" => $request->input('fname') . ' ' . $request->input('lname'),
                    "firstName" => $request->input('fname'),
                    "lastName" => $request->input('lname'),
                    "job_title" => $request->input('job_title'),
                    "phoneNo" => $request->input('phone_no'),
                    "account_type" => $request->input('is_super_admin'),
                    "app_id" => $request->input('app_id'),
                ]);

                if ($request->filled('password')) {
                    $userUpdate->update(["password" => Hash::make($request->input('password'))]);
                }
            }

            return redirect()->route('escrow.users')->with('message', 'User account was updated successfully.');
        } catch (Exception $e) {
            return redirect()->back()->with('message', $e->getMessage() . ' - Something went wrong.');
        }
    }

    public function deleteUser(Request $request)
    {
        try {
            $user = User::find($request['user_id']);
            if ($user) {
                $user->delete();
                return redirect()->route('escrow.users')->with('message', 'User was deleted successfully');
            }
            return redirect()->back()->with('message', 'Your action was unsuccessful');
        } catch (Exception $e) {
            return redirect()->back()->with('message', $e->getMessage());
        }
    }

    public function blockUser(Request $request)
    {
        $user = User::find($request['userID']);
        if ($user) {
            if ($user->status == 1) {
                $user->update(['status' => 0]);
                return redirect()->route('escrow.users')->with('message', 'User was blocked successfully');
            } else {
                $user->update(['status' => 1]);
                return redirect()->route('escrow.users')->with('message', 'User was unblocked successfully');
            }
        }
        return redirect()->back();
    }
}
