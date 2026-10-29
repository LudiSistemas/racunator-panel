<?php
declare(strict_types=1);

namespace RacunatorPanel\Repositories;

use RacunatorPanel\Models\Receipt;

interface ReceiptRepository
{
    /** Upis ako ne postoji (clientId, invoiceNumber); vraća true ako je upisan, false ako je duplikat. */
    public function insertIfNew(int $clientId, array $data): bool;
    /** Računi jednog korisnika za mesec `YYYY-MM`, po vremenu rastuće. @return list<Receipt> */
    public function forClientMonth(int $clientId, string $month, int $limit = 1000, int $offset = 0): array;
    public function findById(int $id): ?Receipt;
    /** Broj računa i zbir u parama po mesecu za korisnika. @return array<string,array{count:int,total:int}> ključ YYYY-MM */
    public function monthlySummary(int $clientId): array;
    /** Broj računa po korisniku u datom mesecu. @return array<int,int> clientId => broj */
    public function countsForMonth(string $month): array;
}
