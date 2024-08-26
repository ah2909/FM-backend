<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Transaction;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Exceptions;

class TransactionController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $transaction = Transaction::where('user_id', Auth::id())->get();
        foreach($transaction as $tran) {
            $tran->category_id = $tran->category->name;
        }
        return response()->json([
            'data' => $transaction,
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
        $data['user_id'] = Auth::id();
        $category = Category::where('name', $request['category'])->first();
        $data['category_id'] = $category->id;

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
            if($transaction->user_id !== Auth::id()) {
                throw new Exception('Do not have permission', 403);
            }
        } catch (\Illuminate\Database\QueryException $err) {
            return response()->json([
                'data' => $err->getMessage(),
            ], 400);
        } catch (Exception $err) {
            return response()->json([
                    'data' => $err->getMessage(),
            ], 403);
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
        $data['user_id'] = Auth::id();
        $category = Category::where('name', $request['category'])->first();
        $data['category_id'] = $category->id;
        
        try {
            $transaction = Transaction::find($transaction_id);
            if($transaction->user_id !== Auth::id()) {
                throw new Exception('Do not have permission', 403);
            }
            $transaction->update($data);
        } catch (\Illuminate\Database\QueryException $err) {
            return response()->json([
                'data' => $err->getMessage(),
            ], 400);
        } catch (Exception $err) {
            return response()->json([
                    'data' => $err->getMessage(),
            ], 403);
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
            if($transaction->user_id !== Auth::id()) {
                throw new Exception('Do not have permission', 403);
            }
            $transaction->delete();
        } catch (\Illuminate\Database\QueryException $err) {
            return response()->json([
                'data' => $err->getMessage(),
            ], 400);
        }  catch (Exception $err) {
            return response()->json([
                    'data' => $err->getMessage(),
            ], 403);
        }

        return response()->json([
            'message' => 'Transaction deleted successfully.',
        ], 204);
    }

    public function transactionStatisticsInMonth() {
        $now = Carbon::now();
        $month = $now->month;
        
        $start = Carbon::createFromDate(null, null, 10);
        $end = Carbon::createFromDate(null, $month + 1, 10);
        try {
            $transaction = Transaction::where('user_id', Auth::id())
                                    ->whereBetween('created_at', [$start, $end])->get();
            $income = 0;
            $expense = 0;

            foreach($transaction as $tran) {
                if($tran->type === 'INCOME') $income += $tran->amount;
                else $expense -= $tran->amount;
            }

            $balance = $income + $expense;

        } catch (\Illuminate\Database\QueryException $err) {
            return response()->json([
                'data' => $err->getMessage(),
            ], 400);
        }

        return response()->json([
            'income' => $income,
            'expense'=> $expense,
            'balance'=> $balance,
        ]);
    }
}
