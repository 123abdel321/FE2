<?php

namespace App\Custom;

use App\TypeCurrency;

class NumberSpellOut
{
    public function convertir($numero, $idcurrency = NULL)
    {
        $izquierda = intval(floor($numero));
        $derecha   = round(($numero - floor($numero)) * 100, 2);

        $letrasIzquierda = strtoupper($this->numeroALetras($izquierda));
        $letrasDerecha   = strtoupper($this->numeroALetras($derecha));

        if ($idcurrency) {
            $idcurrency = TypeCurrency::findOrFail($idcurrency);
            $moneda = strtoupper($idcurrency->name);
        } else {
            $moneda = 'PESOS';
        }

        return $letrasIzquierda . ' ' . $moneda . ' CON ' . $letrasDerecha . ' CENTAVOS';
    }

    /**
     * Convierte un número entero a letras en español.
     */
    private function numeroALetras($numero)
    {
        $numero = (int) $numero;

        if ($numero === 0) {
            return 'CERO';
        }

        if ($numero < 0) {
            return 'MENOS ' . $this->numeroALetras(abs($numero));
        }

        // Escalas: unidades, miles, millones, billones...
        $escalas = [
            '', 'MIL', 'MILLON', 'MIL MILLONES', 'BILLON', 'MIL BILLONES',
            'TRILLON', 'MIL TRILLONES', 'CUATRILLON'
        ];

        $grupos = [];
        while ($numero > 0) {
            $grupos[] = $numero % 1000;
            $numero = intval($numero / 1000);
        }

        $partes = [];
        $totalGrupos = count($grupos);

        for ($i = $totalGrupos - 1; $i >= 0; $i--) {
            $grupo = $grupos[$i];
            if ($grupo === 0) {
                continue;
            }

            $texto = $this->grupoACentenas($grupo);

            // Apócope: "uno" → "un" antes de MIL / MILLON
            if (in_array($i, [1, 2, 4, 6, 8]) && substr($texto, -3) === 'UNO') {
                $texto = substr($texto, 0, -3) . 'UN';
            }

            // Singular/plural para MILLON, BILLON, etc.
            $escala = $escalas[$i];
            if (in_array($i, [2, 4, 6, 8])) {
                if ($grupo == 1) {
                    $escala = str_replace('MILLON', 'MILLON', $escala);
                    $escala = str_replace('BILLON', 'BILLON', $escala);
                    $escala = str_replace('TRILLON', 'TRILLON', $escala);
                    $escala = str_replace('CUATRILLON', 'CUATRILLON', $escala);
                } else {
                    $escala = str_replace('MILLON', 'MILLONES', $escala);
                    $escala = str_replace('BILLON', 'BILLONES', $escala);
                    $escala = str_replace('TRILLON', 'TRILLONES', $escala);
                    $escala = str_replace('CUATRILLON', 'CUATRILLONES', $escala);
                }
            }

            // "UN MIL" → "MIL"
            if ($i === 1 && $grupo === 1) {
                $partes[] = 'MIL';
            } else {
                $partes[] = trim($texto . ' ' . $escala);
            }
        }

        return trim(implode(' ', $partes));
    }

    /**
     * Convierte un grupo de 0 a 999 a letras.
     */
    private function grupoACentenas($num)
    {
        $centenas = [
            0 => '', 100 => 'CIENTO', 200 => 'DOSCIENTOS', 300 => 'TRESCIENTOS',
            400 => 'CUATROCIENTOS', 500 => 'QUINIENTOS', 600 => 'SEISCIENTOS',
            700 => 'SETECIENTOS', 800 => 'OCHOCIENTOS', 900 => 'NOVECIENTOS'
        ];

        if ($num === 100) {
            return 'CIEN';
        }

        $texto = '';

        $c = intval($num / 100) * 100;
        if ($c > 0) {
            $texto .= $centenas[$c] . ' ';
        }
        $resto = $num % 100;

        if ($resto === 0) {
            return trim($texto);
        }

        $decenas = [
            10 => 'DIEZ', 11 => 'ONCE', 12 => 'DOCE', 13 => 'TRECE', 14 => 'CATORCE',
            15 => 'QUINCE', 16 => 'DIECISEIS', 17 => 'DIECISIETE', 18 => 'DIECIOCHO',
            19 => 'DIECINUEVE', 20 => 'VEINTE', 21 => 'VEINTIUNO', 22 => 'VEINTIDOS',
            23 => 'VEINTITRES', 24 => 'VEINTICUATRO', 25 => 'VEINTICINCO',
            26 => 'VEINTISEIS', 27 => 'VEINTISIETE', 28 => 'VEINTIOCHO',
            29 => 'VEINTINUEVE'
        ];

        if (isset($decenas[$resto])) {
            return trim($texto . $decenas[$resto]);
        }

        $decenasBase = [
            30 => 'TREINTA', 40 => 'CUARENTA', 50 => 'CINCUENTA',
            60 => 'SESENTA', 70 => 'SETENTA', 80 => 'OCHENTA', 90 => 'NOVENTA'
        ];

        $d = intval($resto / 10) * 10;
        $u = $resto % 10;

        $textoDecena = $decenasBase[$d];

        if ($u === 0) {
            return trim($texto . $textoDecena);
        }

        $unidades = [
            1 => 'UNO', 2 => 'DOS', 3 => 'TRES', 4 => 'CUATRO', 5 => 'CINCO',
            6 => 'SEIS', 7 => 'SIETE', 8 => 'OCHO', 9 => 'NUEVE'
        ];

        return trim($texto . $textoDecena . ' Y ' . $unidades[$u]);
    }
}