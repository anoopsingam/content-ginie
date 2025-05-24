<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;

use App\Jobs\GenerateProductContentJob;
use App\Models\ProductContentGeneration;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductContentController extends Controller
{
    public function generateContent(Request $request)
    {
        $validated = $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:10240', // 10MB max
            'prices' => 'required|array|min:1',
            'prices.*.country' => 'required|string|max:10',
            'prices.*.price' => 'required|numeric|min:0',
            'prices.*.currency' => 'required|string|max:5',
            'category' => 'required|string|max:100',
            'product_type' => 'required|string|max:100',
            'sample_title' => 'nullable|string|max:200',
            'brand' => 'nullable|string|max:100',
            'ai_provider' => ['nullable', Rule::in(['openai', 'gemini', 'claude'])],
        ]);

        try {
           // Get the uploaded image file
            $file = $request->file('image');

            // Read the image contents and encode as base64
            $imageData = base64_encode(file_get_contents($file->getRealPath()));

            // Set the image path as a data URI with the correct mime type
            $mimeType = $file->getMimeType();
            $imagePath = 'data:' . $mimeType . ';base64,' . $imageData;


            // Create generation record
            $generation = ProductContentGeneration::create([
                'request_id' => Str::uuid(),
                'image_path' => $imagePath,
                'prices' => $validated['prices'],
                'category' => $validated['category'],
                'product_type' => $validated['product_type'],
                'sample_title' => $validated['sample_title'] ?? null,
                'brand' => $validated['brand'] ?? 'Fashion and Clothing',
                'status' => 'pending',
                'ai_provider' => $validated['ai_provider'] ?? 'openai'
            ]);

            // Dispatch background job
            GenerateProductContentJob::dispatch($generation->id, $validated['ai_provider']);
            // Delay to allow the record to be saved

            return response()->json([
                'success' => true,
                'message' => 'Content generation started',
                'request_id' => $generation->request_id,
                'status' => $generation->status
            ], 202);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to start content generation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getStatus($requestId)
    {
        $generation = ProductContentGeneration::where('request_id', $requestId)->first();

        if (!$generation) {
            return response()->json([
                'success' => false,
                'message' => 'Request not found'
            ], 404);
        }

        $response = [
            'success' => true,
            'request_id' => $generation->request_id,
            'status' => $generation->status,
            'created_at' => $generation->created_at,
            'updated_at' => $generation->updated_at,
        ];

        if ($generation->status === 'completed') {
            $response['data'] = [
                'generated_content' => $generation->generated_content,
                'translated_content' => $generation->translated_content,
            ];
        } elseif ($generation->status === 'failed') {
            $response['error'] = $generation->error_message;
        }

        return response()->json($response);
    }

    public function getContent($requestId)
    {
        $generation = ProductContentGeneration::where('request_id', $requestId)
            ->where('status', 'completed')
            ->first();

        if (!$generation) {
            return response()->json([
                'success' => false,
                'message' => 'Completed content not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'request_id' => $generation->request_id,
            'data' => [
                'generated_content' => $generation->generated_content,
                'translated_content' => $generation->translated_content,
                'input_data' => [
                    'prices' => $generation->prices,
                    'category' => $generation->category,
                    'product_type' => $generation->product_type,
                    'sample_title' => $generation->sample_title,
                    'brand' => $generation->brand,
                ]
            ]
        ]);
    }
}
