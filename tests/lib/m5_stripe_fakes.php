<?php
// Shared fakes for the msp_m5_* charge-attempt tests: a PaymentIntent object whose cancel() really changes its status, a store of them and the REAL
// chargeAttemptPiOps() lookup over it (retrieve by id, search by attempt key).
class M5Pi
{
    public $next_action = null; public $payment_method = null; public $cancels = 0;
    public function __construct(public string $id, public string $status, public string $key = '') {}
    public function cancel() { if (in_array($this->status, ['succeeded', 'processing', 'canceled'], true)) { throw new RuntimeException('cannot cancel ' . $this->status); } $this->status = 'canceled'; $this->cancels++; return $this; }
}
class M5Stripe
{
    public array $pis = []; public bool $searchDown = false; public array $searches = [];
    public function add(string $id, string $status, string $key = '', bool $bank = false): M5Pi { $p = new M5Pi($id, $status, $key); if ($bank) { $p->next_action = (object) ['type' => 'verify_with_microdeposits']; } return $this->pis[$id] = $p; }
    public function lookup(): callable
    {
        return chargeAttemptPiOps(
            function (string $id) { if (!isset($this->pis[$id])) { throw \Stripe\Exception\InvalidRequestException::factory('No such payment_intent: ' . $id, 404, null, null, null, 'resource_missing'); } return $this->pis[$id]; },
            function (string $key) { $this->searches[] = $key; if ($this->searchDown) { throw new RuntimeException('search unavailable'); } return array_values(array_filter($this->pis, fn ($p) => $p->key === $key)); }
        );
    }
}
