<?php

namespace App\Console\Commands;

use App\Models\RhUser;
use App\Support\PerimetreEtablissement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * « Pourquoi ce compte ne voit-il pas son établissement ? »
 *
 * Un compte voit un établissement quand TROIS conditions sont réunies : une affectation
 * active (console_affectations) vers ce code, un établissement qui existe sous ce code
 * (ECONOMAT.BEtablissements ou console_etablissements), et qui n'est pas désactivé.
 * Cette commande vérifie chacune et dit laquelle manque. Elle ne modifie rien.
 *
 *   php artisan nexora:expliquer-acces KONE
 */
class ExpliquerAcces extends Command
{
    protected $signature = 'nexora:expliquer-acces {login : Login, email, matricule ou nom du compte}';

    protected $description = "Explique quels établissements un compte peut voir, et pourquoi.";

    public function handle(): int
    {
        $saisi = trim($this->argument('login'));
        $user = RhUser::on('master')->where('Login', $saisi)->orWhere('Email', $saisi)->orWhere('Matricule', $saisi)->first();
        if (! $user) {
            // À défaut, le nom affiché en haut de l'écran (ex. « KONE ») : on liste les homonymes.
            $homonymes = RhUser::on('master')->where('Nom', $saisi)->get(['Id', 'Login', 'Nom', 'Prenom']);
            if ($homonymes->count() > 1) {
                $this->warn("Plusieurs comptes portent le nom « {$saisi} » : relancez avec leur login.");
                foreach ($homonymes as $h) {
                    $this->line("   login « {$h->Login} » — {$h->Nom} {$h->Prenom}");
                }

                return self::FAILURE;
            }
            $user = $homonymes->first() ? RhUser::on('master')->find($homonymes->first()->Id) : null;
        }
        if (! $user) {
            $this->error("Aucun compte RH_USER avec le login, l'email ou le matricule « {$saisi} ».");

            return self::FAILURE;
        }

        $this->line("Compte : <options=bold>{$user->Login}</> — {$user->Nom} {$user->Prenom} (Id {$user->Id})");
        $this->line('Super Admin : '.($user->isSuperAdmin() ? 'oui (voit tout)' : 'non'));
        $this->line('Supprimé / désactivé : '.($user->estSupprime() ? '<fg=red>OUI — il ne peut pas travailler</>' : 'non'));
        $this->line('Établissement de rattachement (RH_USER.Etab) : '.(trim((string) $user->Etab) ?: '—'));
        $this->newLine();

        // 1. Affectations
        $this->line('<options=bold>1. Affectations (console_affectations)</>');
        try {
            $affs = DB::connection('ecoprim')->table('console_affectations as a')
                ->leftJoin('console_roles as r', 'r.id', '=', 'a.role_id')
                ->where('a.rh_user_id', $user->Id)
                ->get(['a.id', 'a.societe_code', 'a.etablissement_code', 'a.actif', 'a.date_debut', 'a.date_fin', 'r.code as role', 'r.nom as role_nom']);
        } catch (Throwable $e) {
            $this->error('   Table console_affectations illisible : '.$e->getMessage());

            return self::FAILURE;
        }
        if ($affs->isEmpty()) {
            $this->line('   <fg=red>AUCUNE affectation pour ce compte.</> C\'est la cause : affectez-le à un établissement avec un rôle.');
        }
        $aujourdhui = now()->toDateString();
        foreach ($affs as $a) {
            $pb = [];
            if (! $a->actif) $pb[] = 'affectation désactivée';
            if ($a->date_fin && $a->date_fin < $aujourdhui) $pb[] = "terminée le {$a->date_fin}";
            if ($a->role === null) $pb[] = 'rôle introuvable';
            $etat = $pb ? '<fg=red>'.implode(', ', $pb).'</>' : '<fg=green>active</>';
            $this->line("   #{$a->id}  établissement « {$a->etablissement_code} »  société « {$a->societe_code} »  rôle {$a->role_nom} ({$a->role})  → {$etat}");
        }
        $this->newLine();

        // 2. Établissements connus
        $this->line('<options=bold>2. Établissements visés</>');
        $codes = $affs->pluck('etablissement_code')->map(fn ($c) => trim((string) $c))->filter()->unique();
        foreach ($codes as $code) {
            $source = null;
            $surcouche = null;
            try { $source = DB::connection('economat')->table('BEtablissements')->whereRaw('LTRIM(RTRIM(CodeEtablissement)) = ?', [$code])->first(); } catch (Throwable $e) {}
            try { $surcouche = DB::connection('ecoprim')->table('console_etablissements')->where('code', $code)->first(); } catch (Throwable $e) {}

            if (! $source && ! $surcouche) {
                $proches = [];
                try {
                    $proches = DB::connection('economat')->table('BEtablissements')->pluck('CodeEtablissement')->map(fn ($c) => trim((string) $c))
                        ->merge(DB::connection('ecoprim')->table('console_etablissements')->pluck('code'))
                        ->filter(fn ($c) => stripos($c, substr($code, 0, 3)) !== false)->unique()->take(5)->implode(', ');
                } catch (Throwable $e) {}
                $this->line("   « {$code} » : <fg=red>INTROUVABLE</> ni dans BEtablissements ni dans console_etablissements."
                    .($proches ? " Codes proches : {$proches}" : ''));
                continue;
            }
            $nom = $surcouche->intitule ?? $source->Intitule ?? $code;
            $actif = $surcouche ? (bool) $surcouche->actif : true;
            $this->line("   « {$code} » : {$nom} — trouvé dans ".($source ? 'BEtablissements' : '').($source && $surcouche ? ' + ' : '').($surcouche ? 'console' : '')
                .' — '.($actif ? '<fg=green>actif</>' : '<fg=red>DÉSACTIVÉ dans la console</>'));
        }
        $this->newLine();

        // 3. Résultat
        $autorises = PerimetreEtablissement::etablissementsAutorises($user);
        $this->line('<options=bold>3. Résultat</>');
        $this->line('   Codes autorisés par les affectations : '.($autorises === null ? 'tous (Super Admin)' : (implode(', ', $autorises) ?: 'aucun')));
        $this->line('   Ce que l\'utilisateur verra à la connexion : les codes ci-dessus qui sont trouvés ET actifs en 2.');

        return self::SUCCESS;
    }
}
