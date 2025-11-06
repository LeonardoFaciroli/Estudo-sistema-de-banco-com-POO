<?php
// rodar o sistema -> & "A:\Wamp\bin\php\php8.4.0\php.exe" "C:\Users\leona\OneDrive\Desktop\SistemaCafe\Banco.php"


declare(strict_types=1);

/**
 * Mini Banco CLI (POO) — entrada em tempo de execução (STDIN)
 * Execute: php banco_cli.php
 */
/* ========================= EXCEÇÕES ========================= */
/**
 * /
 * O que são essas exceções ?
 * 
 */
class BancoException extends RuntimeException {}
class OperacaoInvalidaException extends InvalidArgumentException {}
class SaldoInsuficienteException extends RuntimeException {}

/* ========================= ENTIDADES ========================= */

class Transacao //CLASSE, dentro dela há o construtor e métodos.
{
    private string $tipo;               // "DEPÓSITO", "SAQUE", "TRANSFERÊNCIA SAÍDA", "TRANSFERÊNCIA ENTRADA", "RENDIMENTO", "TARIFA"
    private float $valor;
    private DateTimeImmutable $dataHora;
    private string $descricao;
    private float $saldoAposOperacao;

    public function __construct(string $tipo, float $valor, DateTimeImmutable $dataHora, string $descricao, float $saldoAposOperacao) // construtor a fim de passar um valor base para os atributos antes, para quando o objeto for criado eles ja virem com valor padrao
    {
        $this->tipo = $tipo;
        $this->valor = $valor;
        $this->dataHora = $dataHora;
        $this->descricao = $descricao;
        $this->saldoAposOperacao = $saldoAposOperacao;
    }

    public function formatarLinha(): string //Método com o função de formatar os dados
    {
        $data  = $this->dataHora->format('Y-m-d H:i:s');
        $valor = number_format($this->valor, 2, ',', '.');
        $saldo = number_format($this->saldoAposOperacao, 2, ',', '.');
        return "{$data} | {$this->tipo} | R$ {$valor} | {$this->descricao} | Saldo após: R$ {$saldo}";
    }
}


/*é um monlde que nao pode ser instanciado
Classe abstrata é um “molde” que não pode ser instanciado. Ela concentra comportos comuns e regras para as filhas, mas deixa pontos em aberto via métodos abstratos (que as filhas devem implementar).

Serve para herança + polimorfismo: você usa Conta como tipo geral, e cada tipo concreto (ex.: ContaCorrente, ContaPoupanca) implementa suas particularidades.

Pode ter construtor, métodos concretos e atributos (como no seu exemplo). O que ela não pode ter é implementação para métodos marcados abstract.

No seu código, a regra variável é: quanto custa sacar/transferir. Você declarou abstract protected function calcularValorTotalSaque(...): cada subclasse define tarifa/percentual próprio.*/
abstract class Conta
{
    protected string $numero;
    protected string $titular;
    protected float  $saldo;
    /** @var Transacao[] */
    protected array  $transacoes = [];

    public function __construct(string $numero, string $titular, float $saldoInicial = 0.0) //Construtor valida e inicializa numero, titular, saldo e já registra a transação de “Saldo inicial” se houver.
    {
        $numero  = trim($numero);
        $titular = trim($titular);

        if ($numero === '' || $titular === '') {
            throw new OperacaoInvalidaException("Número e titular não podem ser vazios.");
        }
        if ($saldoInicial < 0) {
            throw new OperacaoInvalidaException("Saldo inicial não pode ser negativo.");
        }

        $this->numero  = $numero;
        $this->titular = $titular;
        $this->saldo   = $saldoInicial;

        if ($saldoInicial > 0) {
            $this->registrarTransacao(new Transacao(
                "DEPÓSITO",
                $saldoInicial,
                new DateTimeImmutable("now"),
                "Saldo inicial",
                $this->saldo
            ));
        }
    }

    public function getNumero(): string
    {
        return $this->numero;
    }
    public function getTitular(): string
    {
        return $this->titular;
    }
    public function getSaldo(): float
    {
        return $this->saldo;
    }


    //Métodos concretos: depositar, sacar, transferir, extrato, registrarTransacao, além dos getters.
    public function depositar(float $valor, string $descricao = "Depósito"): void //
    {
        if ($valor <= 0) {
            throw new OperacaoInvalidaException("Valor de depósito deve ser positivo.");
        }
        $this->saldo += $valor;
        $this->registrarTransacao(new Transacao("DEPÓSITO", $valor, new DateTimeImmutable("now"), $descricao, $this->saldo));
    }

    public function sacar(float $valor, string $descricao = "Saque"): void
    {
        if ($valor <= 0) {
            throw new OperacaoInvalidaException("Valor de saque deve ser positivo.");
        }
        $total = $this->calcularValorTotalSaque($valor);
        if ($total > $this->saldo) {
            throw new SaldoInsuficienteException("Saldo insuficiente.");
        }
        $this->saldo -= $total;

        // registra a parte "saque" (valor solicitado)
        $this->registrarTransacao(new Transacao("SAQUE", $valor, new DateTimeImmutable("now"), $descricao, $this->saldo));

        // se houver tarifa, registrar separadamente
        $tarifa = $total - $valor;
        if ($tarifa > 0) {
            $this->registrarTransacao(new Transacao("TARIFA", $tarifa, new DateTimeImmutable("now"), "Tarifa de saque", $this->saldo));
        }
    }

    public function transferir(float $valor, Conta $destino, string $descricao = "Transferência"): void
    {
        if ($valor <= 0) {
            throw new OperacaoInvalidaException("Valor de transferência deve ser positivo.");
        }
        if ($destino === $this) {
            throw new OperacaoInvalidaException("Conta de destino não pode ser a mesma.");
        }

        $total = $this->calcularValorTotalSaque($valor);
        if ($total > $this->saldo) {
            throw new SaldoInsuficienteException("Saldo insuficiente para transferência.");
        }

        // debita origem
        $this->saldo -= $total;
        $this->registrarTransacao(new Transacao(
            "TRANSFERÊNCIA SAÍDA",
            $valor,
            new DateTimeImmutable("now"),
            $descricao . " para " . $destino->getNumero(),
            $this->saldo
        ));
        $tarifa = $total - $valor;
        if ($tarifa > 0) {
            $this->registrarTransacao(new Transacao("TARIFA", $tarifa, new DateTimeImmutable("now"), "Tarifa de transferência", $this->saldo));
        }

        // credita destino
        $destino->creditarTransferencia($valor, "Transferência de " . $this->getNumero());
    }

    protected function creditarTransferencia(float $valor, string $descricao): void
    {
        $this->saldo += $valor;
        $this->registrarTransacao(new Transacao("TRANSFERÊNCIA ENTRADA", $valor, new DateTimeImmutable("now"), $descricao, $this->saldo));
    }
    //Ponto de extensão: calcularValorTotalSaque($valorSolicitado) é abstrato → cada tipo de conta define como calcula tarifas.
    //um método abstrato protegido.
    //abstract: a classe que declara não implementa o método; obriga cada subclasse concreta a fornecer sua própria implementação.
    //protected: só é acessível dentro da classe e das subclasses (não é público).
    //assinatura: recebe float $valorSolicitado e deve retornar float (o custo total do saque, que é o valor solicitado + eventuais tarifas).
    abstract protected function calcularValorTotalSaque(float $valorSolicitado): float;

    public function extrato(): string
    {
        $linhas = [];
        $linhas[] = "=== Extrato da conta {$this->numero} ({$this->titular}) ===";
        foreach ($this->transacoes as $t) {
            $linhas[] = $t->formatarLinha();
        }
        $linhas[] = "Saldo atual: R$ " . number_format($this->saldo, 2, ',', '.');
        return implode(PHP_EOL, $linhas);
    }

    protected function registrarTransacao(Transacao $t): void
    {
        $this->transacoes[] = $t;
    }
}




class ContaCorrente extends Conta
{
    private float $tarifaOperacao;

    public function __construct(string $numero, string $titular, float $saldoInicial = 0.0, float $tarifaOperacao = 1.50)
    {
        parent::__construct($numero, $titular, $saldoInicial);
        if ($tarifaOperacao < 0) {
            throw new OperacaoInvalidaException("Tarifa não pode ser negativa.");
        }
        $this->tarifaOperacao = $tarifaOperacao;
    }

    protected function calcularValorTotalSaque(float $valorSolicitado): float
    {
        return $valorSolicitado + $this->tarifaOperacao;
    }
}

class ContaPoupanca extends Conta
{
    protected function calcularValorTotalSaque(float $valorSolicitado): float
    {
        return $valorSolicitado; // sem tarifa
    }

    public function aplicarRendimentoMensal(float $taxaPercentual): void
    {
        if ($taxaPercentual < 0) {
            throw new OperacaoInvalidaException("Taxa de rendimento não pode ser negativa.");
        }
        $rendimento = $this->saldo * ($taxaPercentual / 100.0);
        if ($rendimento <= 0) {
            return;
        }
        $this->saldo += $rendimento;
        $this->registrarTransacao(new Transacao("RENDIMENTO", $rendimento, new DateTimeImmutable("now"), "Rendimento mensal", $this->saldo));
    }
}

/* ========================= AGREGADO: BANCO ========================= */

class Banco
{ /*@var array<string, Conta>
    Significa: “isto é um array cujas chaves são string e os valores são objetos do tipo Conta”.

Serve para: ajudar IDEs e analisadores estáticos (PHPStan, Psalm) com autocompletar, inferência de tipos e detecção de bugs (ex.: avisar se você colocar algo que não seja Conta).

Em tempo de execução: não faz nada; é só documentação + verificação estática.

Benefício prático: evita erros silenciosos, melhora leitura do código e a qualidade dos warnings do linter.
    */
    /** @var array<string, Conta> */
    private array $contas = [];

    public function criarContaCorrente(string $numero, string $titular, float $saldoInicial = 0.0, float $tarifa = 1.50): ContaCorrente
    {
        $this->garantirContaInexistente($numero);
        $c = new ContaCorrente($numero, $titular, $saldoInicial, $tarifa);
        $this->contas[$numero] = $c;
        return $c;
    }

    public function criarContaPoupanca(string $numero, string $titular, float $saldoInicial = 0.0): ContaPoupanca
    {
        $this->garantirContaInexistente($numero);
        $c = new ContaPoupanca($numero, $titular, $saldoInicial);
        $this->contas[$numero] = $c;
        return $c;
    }

    public function buscarConta(string $numero): Conta
    {
        if (!isset($this->contas[$numero])) {
            throw new BancoException("Conta {$numero} não encontrada.");
        }
        return $this->contas[$numero];
    }

    public function transferir(string $origem, string $destino, float $valor, string $descricao = "Transferência via Banco"): void
    {
        $c1 = $this->buscarConta($origem);
        $c2 = $this->buscarConta($destino);
        $c1->transferir($valor, $c2, $descricao);
    }

    public function listarContas(): string
    {
        if (!$this->contas) return "Nenhuma conta criada.";
        $linhas = ["=== Contas existentes ==="];
        foreach ($this->contas as $num => $conta) {
            $saldo = number_format($conta->getSaldo(), 2, ',', '.');
            $linhas[] = "{$num} | Titular: {$conta->getTitular()} | Saldo: R$ {$saldo} | Tipo: " . get_class($conta);
        }
        return implode(PHP_EOL, $linhas);
    }

    private function garantirContaInexistente(string $numero): void
    {
        if (isset($this->contas[$numero])) {
            throw new BancoException("Já existe conta com o número {$numero}.");
        }
    }
}

/* ========================= UTIL: ENTRADA CLI ========================= */

/**
 * Lê uma linha do usuário. Usa readline() se existir; senão, fgets(STDIN).
 */
function input(string $prompt): string
{
    if (function_exists('readline')) {
        $r = readline($prompt);
        // adiciona ao histórico (opcional, se quiser navegar com setas)
        if ($r !== false && $r !== '') {
            readline_add_history($r);
        }
        return $r === false ? '' : $r;
    }
    echo $prompt;
    $r = fgets(STDIN);
    return $r === false ? '' : rtrim($r, "\r\n");
}

function inputFloat(string $prompt): float
{
    while (true) {
        $txt = str_replace(',', '.', trim(input($prompt)));
        if ($txt === '') return 0.0;
        if (is_numeric($txt)) return (float)$txt;
        echo "Valor inválido. Tente novamente.\n";
    }
}

/* ========================= APLICAÇÃO CLI ========================= */

$banco = new Banco();

function menu(): void
{
    echo PHP_EOL;
    echo "================= MENU BANCO =================" . PHP_EOL;
    echo "1) Criar Conta Corrente" . PHP_EOL;
    echo "2) Criar Conta Poupança" . PHP_EOL;
    echo "3) Depositar" . PHP_EOL;
    echo "4) Sacar" . PHP_EOL;
    echo "5) Transferir" . PHP_EOL;
    echo "6) Aplicar rendimento (Poupança)" . PHP_EOL;
    echo "7) Extrato" . PHP_EOL;
    echo "8) Listar contas" . PHP_EOL;
    echo "9) Sair" . PHP_EOL;
    echo "==============================================" . PHP_EOL;
}

while (true) {
    menu();
    $op = trim(input("Escolha uma opção: "));

    try {
        switch ($op) {
            case '1': { // criar CC
                    $num    = input("Número da conta (ex.: 0001-CC): ");
                    $tit    = input("Titular: ");
                    $saldo  = inputFloat("Saldo inicial (ex.: 500.00): ");
                    $tarifa = inputFloat("Tarifa por operação (ex.: 2.50): ");
                    $conta  = $banco->criarContaCorrente($num, $tit, $saldo, $tarifa);
                    echo "Conta Corrente criada: {$conta->getNumero()} (Titular: {$conta->getTitular()})" . PHP_EOL;
                    break;
                }
            case '2': { // criar PP
                    $num    = input("Número da conta (ex.: 0002-PP): ");
                    $tit    = input("Titular: ");
                    $saldo  = inputFloat("Saldo inicial (ex.: 200.00): ");
                    $conta  = $banco->criarContaPoupanca($num, $tit, $saldo);
                    echo "Conta Poupança criada: {$conta->getNumero()} (Titular: {$conta->getTitular()})" . PHP_EOL;
                    break;
                }
            case '3': { // depositar
                    $num   = input("Número da conta: ");
                    $valor = inputFloat("Valor do depósito: ");
                    $desc  = input("Descrição (opcional): ");
                    $banco->buscarConta($num)->depositar($valor, $desc ?: "Depósito");
                    echo "Depósito realizado." . PHP_EOL;
                    break;
                }
            case '4': { // sacar
                    $num   = input("Número da conta: ");
                    $valor = inputFloat("Valor do saque: ");
                    $desc  = input("Descrição (opcional): ");
                    $banco->buscarConta($num)->sacar($valor, $desc ?: "Saque");
                    echo "Saque realizado." . PHP_EOL;
                    break;
                }
            case '5': { // transferir
                    $origem  = input("Conta origem: ");
                    $destino = input("Conta destino: ");
                    $valor   = inputFloat("Valor da transferência: ");
                    $desc    = input("Descrição (opcional): ");
                    $banco->transferir($origem, $destino, $valor, $desc ?: "Transferência via Banco");
                    echo "Transferência concluída." . PHP_EOL;
                    break;
                }
            case '6': { // rendimento poupança
                    $num  = input("Número da conta Poupança: ");
                    $taxa = inputFloat("Taxa percentual mensal (ex.: 0.6 para 0,6%): ");
                    $c    = $banco->buscarConta($num);
                    if (!($c instanceof ContaPoupanca)) {
                        echo "A conta informada não é Poupança." . PHP_EOL;
                        break;
                    }
                    $c->aplicarRendimentoMensal($taxa);
                    echo "Rendimento aplicado." . PHP_EOL;
                    break;
                }
            case '7': { // extrato
                    $num = input("Número da conta: ");
                    $c   = $banco->buscarConta($num);
                    echo $c->extrato() . PHP_EOL;
                    break;
                }
            case '8': { // listar
                    echo $banco->listarContas() . PHP_EOL;
                    break;
                }
            case '9':
            case 'q':
            case 'Q': {
                    echo "Saindo...\n";
                    exit(0);
                }
            default:
                echo "Opção inválida.\n";
        }
    } catch (Throwable $e) {
        // Tratamento simples de erros para o CLI
        echo "[ERRO] " . $e->getMessage() . PHP_EOL;
        echo "(Tipo: " . get_class($e) . ")\n";
    }
}
