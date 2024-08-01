<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TransactionController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return response()->json([
            'data' => Transaction::all(),
        ]);
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
        $data['user_id'] = $request->input('user_id');
        $data['category_id'] = $request->input('category_id');

        try {
            $transaction = Transaction::create($data);
        } catch (\Illuminate\Database\QueryException $err) {
            return response()->json([
                'data' => $err->getMessage(),
            ], 400);
        }
        

        return response()->json([
            'data' => $transaction,
        ], 201);
    }

    /**
     * Display the specified resource.
     *
     * @param Products $product
     * @return \Illuminate\Http\Response
     */
    public function show($transaction_id)
    {
        try {
            $transaction = Transaction::find($transaction_id);
        } catch (\Illuminate\Database\QueryException $err) {
            return response()->json([
                'data' => $err->getMessage(),
            ], 400);
        }

        return response()->json([
            'data' => $transaction,
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param Products $product
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $transaction_id)
    {
        $data = $request->all();
        
        try {
            $transaction = Transaction::find($transaction_id);
            $transaction->update($data);
        } catch (\Illuminate\Database\QueryException $err) {
            return response()->json([
                'data' => $err->getMessage(),
            ], 400);
        }

        return response()->json([
            'data' => $transaction,
        ], 201);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Products $product
     * @return \Illuminate\Http\Response
     */
    public function destroy($transaction_id)
    {
        try {
            $transaction = Transaction::find($transaction_id);
            if($transaction) {
                $transaction->delete();
                return response()->json([
                    'message' => 'Transaction deleted successfully.',
                ], 204);
            }
        } catch (\Illuminate\Database\QueryException $err) {
            return response()->json([
                'data' => $err->getMessage(),
            ], 400);
        }  
    }
}
