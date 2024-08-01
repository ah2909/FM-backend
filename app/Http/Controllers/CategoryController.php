<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return response()->json([
            'data' => Category::all(),
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

        try {
            $category = Category::create($data);
        } catch (\Illuminate\Database\QueryException $err) {
            return response()->json([
                'data' => $err->getMessage(),
            ], 400);
        }
        

        return response()->json([
            'data' => $category,
        ], 201);
    }

    /**
     * Display the specified resource.
     *
     * @param Products $product
     * @return \Illuminate\Http\Response
     */
    public function show($category_id)
    {
        try {
            $category = Category::find($category_id);
        } catch (\Illuminate\Database\QueryException $err) {
            return response()->json([
                'data' => $err->getMessage(),
            ], 400);
        }

        return response()->json([
            'data' => $category,
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param Products $product
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $category_id)
    {
        $data = $request->all();
        
        try {
            $category = Category::find($category_id);
            $category->update($data);
        } catch (\Illuminate\Database\QueryException $err) {
            return response()->json([
                'data' => $err->getMessage(),
            ], 400);
        }

        return response()->json([
            'data' => $category,
        ], 201);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Products $product
     * @return \Illuminate\Http\Response
     */
    public function destroy($category_id)
    {
        try {
            $category = Category::find($category_id);
            if($category) {
                $category->delete();
                return response()->json([
                    'message' => 'Category deleted successfully.',
                ], 204);
            }
        } catch (\Illuminate\Database\QueryException $err) {
            return response()->json([
                'data' => $err->getMessage(),
            ], 400);
        }  
    }

}
