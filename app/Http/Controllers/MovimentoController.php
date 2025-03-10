<?php

namespace App\Http\Controllers;

use App\Deposito;
use App\Estoque;
use App\itemMovimento;
use App\Material;
use App\MaterialNotas;
use App\Movimento;
use App\NotaFiscal;
use App\Notificacao;
use App\Transferencia;
use App\Usuario;
use App\Setor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MovimentoController extends Controller
{
    public function createEntrada()
    {
        $notas = NotaFiscal::where('status', '!=', 'Concluido')->get();
        $setores = Setor::all();

        return view('movimento.entrada', compact('notas', 'setores'));
    }

    public function createSaida()
    {
        $materiais = Material::all();
        $depositos = Deposito::all();

        return view('movimento.saida', ['materiais' => $materiais, 'depositos' => $depositos]);
    }

    public function createTransferencia()
    {
        $materiais = Material::all();
        $depositos = Deposito::all();

        return view('movimento.transferencia', ['materiais' => $materiais, 'depositos' => $depositos]);
    }

    private function notificacao_E_Email($estoque){
        $material = Material::find($estoque->material_id);
        $usuarios = Usuario::all();
        if($estoque->quantidade < $material->quantidade_minima){
            for ($j = 1; $j < count($usuarios); ++$j) {
                if (2 == $usuarios->find($j)->cargo_id) {
                    $usuario = $usuarios->find($j);
                    \App\Jobs\emailMaterialEsgotando::dispatch($usuario, $material);
                    $mensagem = $material->nome . ' em estado critico.';
                    $notificacao = new Notificacao();
                    $notificacao->mensagem = $mensagem;
                    $notificacao->usuario_id = $usuario->id;
                    $notificacao->material_id = $material->id;
                    $notificacao->material_quant = $estoque->quantidade;
                    $notificacao->visto = false;
                    $notificacao->save();
                }
            }
        }
    }

    public function entradaStore(Request $request)
    {
        $validator = Validator::make($request->all(), Movimento::$rules, Movimento::$messages)->validate();

        $notaFiscal = NotaFiscal::find($request->nota_fiscal_id);

        $notasMateriais = MaterialNotas::where('nota_fiscal_id', $notaFiscal->id)->get();

        foreach ($notasMateriais as $notaM){
            $movimentoEntrada = new Movimento();
            $movimentoEntrada->operacao = $request['operacao'];
            $movimentoEntrada->descricao = $request['descricao'];

            $estoque = Estoque::where([
                ['setor_id', '=', $request->setor_id],
                ['material_id', '=', $notaM->material_id],
            ])->first();

            if ($estoque == null) {
                $estoque = new Estoque();
                $estoque->quantidade = $notaM->quantidade;
                $estoque->material_id = $notaM->material_id;
                $estoque->deposito_id = 1;
                $estoque->setor_id = $request->setor_id;

                $this->notificacao_E_Email($estoque);
                $estoque->save();
            } else {
                $estoque->quantidade += $notaM->quantidade;

                $this->notificacao_E_Email($estoque);

                $estoque->update();
            }

            $movimentoEntrada->save();


            $itemMovimento = new itemMovimento();
            $itemMovimento->quantidade = $notaM->quantidade;
            $itemMovimento->material_id = $notaM->material_id;
            $itemMovimento->estoque_id = $estoque->id;
            $itemMovimento->movimento_id = $movimentoEntrada->id;
            $itemMovimento->nota_fiscal_id = $request->nota_fiscal_id;

            $itemMovimento->save();
        }

        $notaFiscal->status = 'Concluido';
        $notaFiscal->update();

        return redirect()->route('movimento.entradaCreate')->with('success', 'Materiais da Nota Fiscal Adicionados com Sucesso no Depósito!');
    }

    public function saidaStore(Request $request)
    {
        $validator = Validator::make($request->all(), Movimento::$rules, Movimento::$messages)->validate();

        $movimentoSaida = new Movimento();
        $movimentoSaida->operacao = $request['operacao'];
        $movimentoSaida->descricao = $request['descricao'];

        $estoque = DB::table('estoques')->where([
            ['deposito_id', '=', $request['deposito_id']],
            ['material_id', '=', $request['material_id']],
        ])->get()->first();

        if (null != $estoque) {
            $estoque = Estoque::findOrFail($estoque->id);
            if ($estoque->quantidade - $request['quantidade'] < 0) {
                $request->session()->flash('erro', 'Quantidade solicitada é maior que a disponível em estoque');

                return redirect()->route('movimento.saidaCreate');
            }
            $estoque->quantidade -= $request['quantidade'];
        } else {
            $request->session()->flash('erro', 'Não existe um estoque do material selecionado nesse depósito');

            return redirect()->route('movimento.saidaCreate');
        }

        $movimentoSaida->save();
        $estoque->save();

        $itemMovimento = new itemMovimento();
        $itemMovimento->quantidade = $request['quantidade'];
        $itemMovimento->material_id = $request['material_id'];
        $itemMovimento->estoque_id = $estoque->id;
        $itemMovimento->movimento_id = $movimentoSaida->id;

        $itemMovimento->save();

        return redirect()->route('deposito.consultarDeposito');
    }

    public function transferenciaStore(Request $request)
    {
        $validator = Validator::make($request->all(), Transferencia::$rules, Transferencia::$messages)->validate();

        $movimentoSaida = new Movimento();
        $movimentoSaida->operacao = '1';
        $movimentoSaida->descricao = $request['descricao'];

        $movimentoEntrada = new Movimento();
        $movimentoEntrada->operacao = '0';
        $movimentoEntrada->descricao = $request['descricao'];

        $estoqueSaida = DB::table('estoques')->where([
            ['deposito_id', '=', $request['deposito_id_origem']],
            ['material_id', '=', $request['material_id']],
        ])->get()->first();

        if (null != $estoqueSaida) {
            $estoqueSaida = Estoque::findOrFail($estoqueSaida->id);
            if ($estoqueSaida->quantidade - $request['quantidade'] < 0) {
                $request->session()->flash('erro', 'Quantidade solicitada é maior que a disponível em estoque');

                return redirect()->route('movimento.transferenciaCreate');
            }
            $estoqueSaida->quantidade -= $request['quantidade'];
            $this->notificacao_E_Email($estoqueSaida);
        } else {
            $request->session()->flash('erro', 'Não existe um estoque do material selecionado nesse depósito');

            return redirect()->route('movimento.transferenciaCreate');
        }

        $estoqueEntrada = DB::table('estoques')->where([
            ['deposito_id', '=', $request['deposito_id_destino']],
            ['material_id', '=', $request['material_id']],
        ])->get()->first();

        if (null == $estoqueEntrada) {
            $estoqueEntrada = new Estoque();
            $estoqueEntrada->quantidade = $request['quantidade'];
            $estoqueEntrada->material_id = $request['material_id'];
            $estoqueEntrada->deposito_id = $request['deposito_id_destino'];
            $this->notificacao_E_Email($estoqueEntrada);
        } else {
            $estoqueEntrada = Estoque::findOrFail($estoqueEntrada->id);
            $estoqueEntrada->quantidade += $request['quantidade'];
            $this->notificacao_E_Email($estoqueEntrada);
        }

        $movimentoSaida->save();
        $estoqueSaida->save();
        $movimentoEntrada->save();
        $estoqueEntrada->save();

        $itemMovimentoSaida = new itemMovimento();
        $itemMovimentoSaida->quantidade = $request['quantidade'];
        $itemMovimentoSaida->material_id = $request['material_id'];
        $itemMovimentoSaida->estoque_id = $estoqueSaida->id;
        $itemMovimentoSaida->movimento_id = $movimentoSaida->id;

        $itemMovimentoEntrada = new itemMovimento();
        $itemMovimentoEntrada->quantidade = $request['quantidade'];
        $itemMovimentoEntrada->material_id = $request['material_id'];
        $itemMovimentoEntrada->estoque_id = $estoqueEntrada->id;
        $itemMovimentoEntrada->movimento_id = $movimentoEntrada->id;

        $itemMovimentoEntrada->save();
        $itemMovimentoSaida->save();

        return redirect()->route('deposito.consultarDeposito');
    }
}
