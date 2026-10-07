<?php

namespace App\Services\Arm;

/**
 * Разнос недоработки по двум ЛС как RAS1LS.PRG / LENA.PRG `prov_dobav`.
 */
class UnderworkAllocator
{
    /**
     * @param  array<string, float>  $first
     * @param  array<string, float>  $second
     * @return array{0: array<string, float>, 1: array<string, float>}
     */
    public function apply(array $first, array $second): array
    {
        if (($second['per1'] ?? 0) > 0) {
            return [$this->zeroDob($first), $this->zeroDob($second)];
        }

        $nor1 = (float) ($first['tek_nr'] ?? 0) - (float) ($first['otvl'] ?? 0);
        $nor2 = (float) ($second['tek_nr'] ?? 0) - (float) ($second['otvl'] ?? 0);
        $otrbp1 = $this->otrbp($first);
        $otrbp2 = $this->otrbp($second) - (float) ($second['nvih'] ?? 0);
        $nehv = $nor1 + $nor2 - $otrbp1 - $otrbp2;
        if ($nehv <= 0) {
            return [$first, $second];
        }

        $pr1ls = $this->holidays($first) + (float) ($first['nvih'] ?? 0);
        $nehv1 = min($nehv, $pr1ls);
        $nehv2 = $nehv - $nehv1;
        $second = $this->fillDob($second, $nehv2);
        $first = $this->fillDob($first, $nehv1);

        return [$first, $second];
    }

    /**
     * @param  array<string, float>  $t
     * @return array<string, float>
     */
    private function zeroDob(array $t): array
    {
        foreach (['dob1', 'dob2', 'dobr', 'dobr2', 'dob2p', 'dobrp'] as $key) {
            $t[$key] = 0.0;
        }

        return $t;
    }

    /**
     * @param  array<string, float>  $t
     */
    private function otrbp(array $t): float
    {
        return (float) ($t['vc1'] ?? 0)
            + (float) ($t['vc2'] ?? 0)
            + (float) ($t['vcr'] ?? 0)
            + (float) ($t['vcr2'] ?? 0)
            + (float) ($t['vc2p'] ?? 0)
            + (float) ($t['vcrp'] ?? 0);
    }

    /**
     * @param  array<string, float>  $t
     */
    private function holidays(array $t): float
    {
        return (float) ($t['prazd1'] ?? 0)
            + (float) ($t['prazd2'] ?? 0)
            + (float) ($t['prazdr'] ?? 0)
            + (float) ($t['prazdr2'] ?? 0)
            + (float) ($t['prazd2p'] ?? 0)
            + (float) ($t['prazdrp'] ?? 0);
    }

    /**
     * @param  array<string, float>  $t
     * @return array<string, float>
     */
    private function fillDob(array $t, float $nehv): array
    {
        if ($nehv <= 0) {
            return $t;
        }
        $order = [
            'dobnv' => 'nvih',
            'dob1' => 'prazd1',
            'dobr' => 'prazdr',
            'dob2' => 'prazd2',
            'dobr2' => 'prazdr2',
            'dob2p' => 'prazd2p',
            'dobrp' => 'prazdrp',
        ];
        foreach ($order as $dob => $source) {
            if ($nehv <= 0) {
                break;
            }
            $t[$dob] = min((float) ($t[$source] ?? 0), $nehv);
            $nehv -= $t[$dob];
        }

        return $t;
    }
}
