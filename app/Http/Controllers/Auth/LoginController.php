<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\DB;
use Horsefly\LoginDetail;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Console\Kernel;
use App\Helpers\PermissionHelper;
use Illuminate\Support\Facades\Log;
class LoginController extends Controller
{
    /**
     * Show the login form.
     *
     * @return \Illuminate\View\View
     */
    public function showLoginForm()
    {
        return view('auth.login');
    }

    protected function authenticated($request, $user)
    {
        return redirect()->to(PermissionHelper::firstAllowedRoute($user));
    }

    /**
     * Handle a login request to the application.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function login(Request $request)
    {
        // Validate the incoming login request
        $credentials = $request->only('email', 'password');

        $validator = Validator::make($credentials, [
            'email' => 'required|email',
            'password' => 'required|min:6',
        ]);

        if ($validator->fails()) {
            return redirect()->route('login')->withErrors($validator)->withInput();
        }

        // Get the IP address of the incoming request
        $originalIp = $request->ip();

        // Fetch the list of active IP addresses from the database using the query builder
        $ip_addresses_db = DB::table('ip_addresses')
            ->where('status', 1) // Ensure 'status' is '1' (active)
            ->where('ip_address', $originalIp)
            ->exists();


        // Check if the modified IP exists in the database (after modification)
        if (!$ip_addresses_db) {
            return redirect()->route('login')->withErrors(['ip' => 'Your IP address is not registered.']);
        }

        // Check if the user has too many login attempts
        if ($this->hasTooManyLoginAttempts($request)) {
            $this->fireLockoutEvent($request);
            return $this->sendLockoutResponse($request);
        }

        // Attempt to log the user in
        if (Auth::attempt($credentials, $request->has('remember'))) {
            // Get the authenticated user
            $user = Auth::user();

            // Check if the user is active
            if ($user->is_active == 1) {
                // Create login details
                // Check if a login detail exists for today
                $today = Carbon::now()->toDateString();
                $loginDetail = LoginDetail::where('user_id', $user->id)
                    ->whereDate('created_at', $today)
                    ->first();

                if ($loginDetail) {
                    // Update logout_at to null if already exists for today
                    $loginDetail->logout_at = null;
                    $loginDetail->save();
                } else {
                    // Create new login detail
                    LoginDetail::create([
                        'user_id' => $user->id,
                        'ip_address' => $request->ip(),
                        'login_at' => Carbon::now(),
                    ]);
                }

                // Regenerate session to prevent session fixation
                $request->session()->regenerate();

                // Redirect to first allowed route based on permissions
                return redirect()->to(PermissionHelper::firstAllowedRoute($user));
            } else {
                // If the user is inactive, log them out and show an error
                Auth::logout();
                return redirect()->route('login')->withErrors(['email' => 'Your account is not active. Please contact support.']);
            }
        }

        // If authentication fails, increment login attempts
        $this->incrementLoginAttempts($request);

        // Redirect back with an error if login fails
        return redirect()->route('login')->withErrors(['email' => 'Invalid credentials. Please try again.'])->withInput();
    }

    protected function hasTooManyLoginAttempts(Request $request)
    {
        // You can set the maximum attempts here. For example, 5 attempts.
        $maxAttempts = 5;
        $decayMinutes = 1; // Lockout time in minutes.

        return RateLimiter::tooManyAttempts($this->throttleKey($request), $maxAttempts, $decayMinutes);
    }

    protected function throttleKey(Request $request)
    {
        return 'login|' . $request->input('email');
    }

    protected function incrementLoginAttempts(Request $request)
    {
        RateLimiter::hit($this->throttleKey($request), 60);
    }

    protected function fireLockoutEvent(Request $request)
    {
        // This can be used to fire a lockout event, you can log an event or notify the user/admin.
        // For example: event(new Lockout($request));
    }

    protected function sendLockoutResponse(Request $request)
    {
        $seconds = RateLimiter::availableIn($this->throttleKey($request));

        return redirect()->route('login')->withErrors(['email' => 'Too many login attempts. Please try again in ' . $seconds . ' seconds.']);
    }

    /**
     * Handle a logout request to the application.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function logout()
    {
        // Get the authenticated user
        $user = Auth::user();

        if ($user) {
            // Update the login_details table with the logout time
            LoginDetail::where('user_id', $user->id)
                ->whereNull('logout_at') // Ensure only the active session gets updated
                ->update(['logout_at' => Carbon::now()]);
        }

        // Now it's safe to log the user out
        Auth::logout();

        // Clear the session data to ensure a full logout
        session()->invalidate();
        session()->regenerateToken();

        // Redirect the user to the login page with a success message
        return redirect()->route('login')->with('message', 'You have been logged out successfully.');
    }

}
