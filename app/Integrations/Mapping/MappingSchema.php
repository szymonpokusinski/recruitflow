<?php

namespace App\Integrations\Mapping;

use Illuminate\Support\Str;

class MappingSchema
{
    public const REQUIRED = ['offers' => ['id', 'title'], 'applications' => ['id', 'offer_id', 'candidate_name'], 'events' => ['id', 'application_id', 'message', 'occurred_at'], 'profiles' => ['id'], 'other' => []];

    public static function definitions(): array
    {
        $definitions = [
            'offers' => [
                'id' => ['Klucz oferty', 'id_oferty,offer_id,oferta_id'], 'title' => ['Stanowisko / tytuł', 'stanowisko,tytul,nazwa_stanowiska,position'],
                'company' => ['Firma', 'firma,pracodawca,company_name'], 'department' => ['Dział', 'dzial'], 'location' => ['Lokalizacja', 'lokalizacja,miasto'],
                'work_mode' => ['Tryb pracy', 'tryb_pracy,workmode'], 'employment' => ['Forma współpracy', 'forma_zatrudnienia,umowa'],
                'salary_min' => ['Wynagrodzenie od', 'wynagrodzenie_min,placa_min'], 'salary_max' => ['Wynagrodzenie do', 'wynagrodzenie_max,placa_max'],
                'currency' => ['Waluta', 'waluta'], 'status' => ['Status', 'status_oferty'], 'owner' => ['Opiekun', 'opiekun,rekruter,hr'], 'created_at' => ['Data utworzenia', 'data_utworzenia,created'],
                'description' => ['Opis', 'opis'], 'requirements' => ['Wymagania', 'wymagania'],
            ],
            'applications' => [
                'id' => ['Klucz aplikacji', 'id_aplikacji,application_id,aplikacja_id'], 'offer_id' => ['Klucz powiązanej oferty', 'id_oferty,oferta_id'],
                'candidate_name' => ['Imię i nazwisko kandydata', 'kandydat,imie_nazwisko,nazwa_kandydata,name'], 'candidate_email' => ['E-mail kandydata', 'email,email_kandydata'],
                'candidate_role' => ['Stanowisko kandydata', 'stanowisko_kandydata'], 'location' => ['Lokalizacja', 'lokalizacja,miasto'],
                'status' => ['Etap', 'etap,status_aplikacji'], 'owner' => ['Opiekun', 'opiekun,rekruter,hr'], 'source' => ['Źródło zgłoszenia', 'zrodlo'],
                'applied_at' => ['Data aplikacji', 'data_aplikacji,data_zgloszenia'], 'next_step' => ['Termin kolejnego kroku', 'termin_rozmowy,nastepny_krok'],
            ],
            'events' => [
                'id' => ['Klucz zdarzenia', 'id_zdarzenia,event_id'], 'application_id' => ['Klucz powiązanej aplikacji', 'id_aplikacji,aplikacja_id'],
                'kind' => ['Rodzaj zdarzenia', 'typ,rodzaj,typ_zdarzenia'], 'actor' => ['Osoba', 'osoba,autor,hr'],
                'message' => ['Opis zdarzenia', 'opis,tresc,zdarzenie,komentarz'], 'occurred_at' => ['Data zdarzenia', 'data,data_zdarzenia,timestamp'],
            ],
            'profiles' => ['id' => ['Klucz profilu', 'id_profilu,profile_id'], 'query' => ['Zapytanie', 'zapytanie,fraza'], 'name' => ['Nazwa', 'nazwa']],
            'other' => [],
        ];
        foreach (RecruitFlowSchemaDetector::FIELDS as $role => $fields) {
            foreach ($fields as $field => $technical) {
                $definitions[$role][$field] ??= [str_replace('_', ' ', $technical), $technical];
            }
        }

        return $definitions;
    }

    public static function required(string $role, string $profile = 'generic'): array
    {
        return $profile === 'recruitflow' ? RecruitFlowSchemaDetector::required($role) : self::REQUIRED[$role];
    }

    public static function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii($value)));
    }

    public static function suggest(string $role, array $headers): array
    {
        $suggested = [];
        $used = [];
        foreach (self::definitions()[$role] ?? [] as $field => [$label, $aliases]) {
            $names = array_map(self::normalize(...), [$field, ...explode(',', $aliases)]);
            $matches = array_values(array_filter($headers, fn ($header) => in_array(self::normalize($header), $names, true)));
            if (count($matches) === 1 && ! in_array($matches[0], $used, true)) {
                $suggested[$field] = $matches[0];
                $used[] = $matches[0];
            }
        }

        return $suggested;
    }

    public static function publicSchemas(): array
    {
        $result = [];
        foreach (self::definitions() as $role => $fields) {
            foreach ($fields as $key => [$label]) {
                $result[$role][] = ['field' => $key, 'label' => $label, 'required' => in_array($key, self::REQUIRED[$role], true)];
            }
        }

        return $result;
    }
}
