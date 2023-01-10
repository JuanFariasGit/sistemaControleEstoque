<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\MovementRequest;
use App\Models\Movement;
use App\Models\Product;
use App\Models\MovementProduct;
use App\Models\User;
use App\Services\MovementProductService;
use App\Services\MovementService;
use App\Services\ProductService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StockController extends Controller
{
    private $productService;
    private $movementService;
    private $movementProductService;

    public function __construct(
        ProductService $productService,
        MovementService $movementService,
        MovementProductService $movementProductService)
    {
        $this->middleware(['auth']);
        $this->productService = $productService;
        $this->movementService = $movementService;
        $this->movementProductService = $movementProductService;
    }

    public function index()
    {
        return view('stock.index');
    }

    public function findAll()
    {
        $movements = $this->movementService->findAll();

        return ['data' => $movements];
    }

    public function add()
    {
        $products = $this->productService->findAll();

        return view('stock.add', ['products' => $products]);
    }

    public function edit(Request $request, $id)
    {
        $movement = $this->movementService->findByIdRelationships($id, 'products');

        if ($movement) {
            $this->authorize('user-movement', $movement);
            
            $products = $movement->products;
    
            return view('stock.edit', ['products' => $products, 'movement' => $movement]);
        }

        abort('404');
    }

    public function addAction(MovementRequest $request)
    {
        $data = $request->all();

        $result = $this->movementProductService->validate($data['idProducts'], $data['quantities'], $data['values']);
        
        if ($result['status']) {
            return redirect()
                ->action('StockController@add')
                ->withErrors($result['validator'], 'products')
                ->withInput();
        }

        $data['user_id'] = $request->user()->id;
        $data['total'] = $this->movementProductService->getTotal($data['quantities'], $result['valuesNumberFormat']);

        $movement = $this->movementService->store($data);

        $this->movementProductService->save($movement->id, $data['idProducts'], $data['quantities'], $result['valuesNumberFormat']);

        return redirect()->route('stock.index')->with('alert', 'Movimentação adicionada com sucesso !');
    }

    public function editAction(MovementRequest $request)
    {
        $movement = $this->movementService->findById($request->id);

        if ($movement) {
            $this->authorize('user-movement', $movement);

            $data = $request->all();

            $result = $this->movementProductService->validate($data['idProducts'], $data['quantities'], $data['values']);

            if ($result['status']) {
                return redirect()
                    ->action('StockController@edit', $data['id'])
                    ->withErrors($result['validator'], 'products')
                    ->withInput();
            }

            $data['user_id'] = $request->user()->id;
            $data['total'] = $this->movementProductService->getTotal($data['quantities'], $result['valuesNumberFormat']);

            $movement->update($data);

            $this->movementProductService->save($movement->id, $data['idProducts'], $data['quantities'], $result['valuesNumberFormat']);
        }

        return redirect()->route('stock.index')->with('alert', 'Movimentação atualizada com sucesso !');
    }

    public function delete(Request $request)
    {
        $movement = Movement::where('user_id', $request->user()->id)
        ->where('id', $request->id)->first();

        if ($movement) {
            $this->deleteProductMovements($request->id);
            $movement->delete();
        }
    }

    public function viewMovement(Request $request, $id)
    {
        $movement = Movement::where('user_id', $request->user()->id)->where('id', $id)->first();
        $products = DB::select('select p.code, p.name, mp.quantity, mp.value from products p inner join movement_products mp on p.id = mp.product_id inner join movements m on m.id = mp.movement_id where m.id = ?', [$id]);
        return ['movement' => $movement, 'products' => $products];
    }

    private function deleteProductMovements($id)
    {
        $movementProducts = MovementProduct::where('movement_id', $id)->get();
        $movement = Movement::find($id);

        foreach ($movementProducts as $movementProduct) {
            $product = Product::find($movementProduct['product_id']);

            if ($movement->type == 'entry') {
                $product->current_stock -= intval($movementProduct['quantity']);
            } else {
                $product->current_stock += intval($movementProduct['quantity']);
            }

            $product->update();
            $movementProduct->delete();
        }
    }
}
