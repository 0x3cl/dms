<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * The public landing page.
 *
 * `/` used to be an inline closure returning a Blade view while this
 * controller sat unrouted; the route points here now.
 */
class LandingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('index');
    }
}
