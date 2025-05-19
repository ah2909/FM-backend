<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use App\Traits\ErrorHandler;

class AssetController extends Controller
{
    use ApiResponse, ErrorHandler;
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        try {
            $user_id = request()->attributes->get('user')->id;
            $asset = User::find($user_id)->assets()->get();
            return $this->successResponse($asset);
        } catch (\Throwable $th) {
            return $this->handleException($th, ['user_id' => $user_id]);
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $data = $request->all();
        try {
            $asset = Asset::create($data);
        } catch (\Illuminate\Database\QueryException $err) {
            return $this->handleException($err, [
                'data' => $data
            ]);
        }
        return $this->successResponse($asset, 'Asset created successfully', 201);
    }
}
