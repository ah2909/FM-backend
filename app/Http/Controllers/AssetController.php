<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Asset;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Exceptions;

class AssetController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $asset = User::find(Auth::id())->assets()->get();
        
        return response()->json([
            'data' => $asset,
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
        // $data['user_id'] = Auth::id();
        // $category = Category::where('name', $request['category'])->first();
        // $data['category_id'] = $category->id;

        try {
            $asset = Asset::create($data);
            $asset->users()->attach(Auth::id());
        } catch (\Illuminate\Database\QueryException $err) {
            return response()->json([
                'data' => $err->getMessage(),
            ], 400);
        }
        
        return response()->json([
            'data' => $asset,
        ], 201);
    }

    /**
     * Display the specified resource.
     *
     * @param Products $product
     * @return \Illuminate\Http\Response
     */
    // public function show($asset_id)
    // {
    //     try {
    //         $asset = asset::find($asset_id);
    //         if($asset->user_id !== Auth::id()) {
    //             throw new Exception('Do not have permission', 403);
    //         }
    //     } catch (\Illuminate\Database\QueryException $err) {
    //         return response()->json([
    //             'data' => $err->getMessage(),
    //         ], 400);
    //     } catch (Exception $err) {
    //         return response()->json([
    //                 'data' => $err->getMessage(),
    //         ], 403);
    //     }

    //     return response()->json([
    //         'data' => $asset,
    //     ]);
    // }

    // /**
    //  * Update the specified resource in storage.
    //  *
    //  * @param \Illuminate\Http\Request $request
    //  * @param Products $product
    //  * @return \Illuminate\Http\Response
    //  */
    // public function update(Request $request, $asset_id)
    // {
    //     $data = $request->all();
    //     $data['user_id'] = Auth::id();
    //     $category = Category::where('name', $request['category'])->first();
    //     $data['category_id'] = $category->id;
        
    //     try {
    //         $asset = asset::find($asset_id);
    //         if($asset->user_id !== Auth::id()) {
    //             throw new Exception('Do not have permission', 403);
    //         }
    //         $asset->update($data);
    //     } catch (\Illuminate\Database\QueryException $err) {
    //         return response()->json([
    //             'data' => $err->getMessage(),
    //         ], 400);
    //     } catch (Exception $err) {
    //         return response()->json([
    //                 'data' => $err->getMessage(),
    //         ], 403);
    //     }

    //     return response()->json([
    //         'data' => $asset,
    //     ], 201);
    // }

    // /**
    //  * Remove the specified resource from storage.
    //  *
    //  * @param Products $product
    //  * @return \Illuminate\Http\Response
    //  */
    // public function destroy($asset_id)
    // {
    //     try {
    //         $asset = asset::find($asset_id);
    //         if($asset->user_id !== Auth::id()) {
    //             throw new Exception('Do not have permission', 403);
    //         }
    //         $asset->delete();
    //     } catch (\Illuminate\Database\QueryException $err) {
    //         return response()->json([
    //             'data' => $err->getMessage(),
    //         ], 400);
    //     }  catch (Exception $err) {
    //         return response()->json([
    //                 'data' => $err->getMessage(),
    //         ], 403);
    //     }

    //     return response()->json([
    //         'message' => 'asset deleted successfully.',
    //     ], 204);
    // }

    // public function assetStatisticsInRange(Request $request) {
    //     $start = $request->input('from');
    //     $end = $request->input('to');
        
    //     try {
    //         $asset = asset::where('user_id', Auth::id())
    //                                 ->whereBetween('created_at', [$start, $end])->get();
    //         $income = 0;
    //         $expense = 0;

    //         foreach($asset as $tran) {
    //             if($tran->type === 'INCOME') $income += $tran->amount;
    //             else $expense -= $tran->amount;
    //         }

    //         $balance = $income + $expense;

    //     } catch (\Illuminate\Database\QueryException $err) {
    //         return response()->json([
    //             'data' => $err->getMessage(),
    //         ], 400);
    //     }

    //     return response()->json([
    //         'income' => $income,
    //         'expense'=> $expense,
    //         'balance'=> $balance,
    //     ]);
    // }
}
