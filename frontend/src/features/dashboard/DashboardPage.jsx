import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import {
  AlertTriangle,
  CalendarClock,
  GraduationCap,
  School,
  TrendingUp,
  Users,
} from 'lucide-react'
import { useAuthStore } from '../../store/authStore'
import { fetchDashboardStats } from './dashboardApi'
import { Anneau, CarteGraphique, HistogrammeMensuel, Jauge } from './graphiques'

const NATURES = {
  evaluation: { libelle: 'Évaluation', classes: 'bg-primary-50 text-primary-700' },
  devoir: { libelle: 'Devoir', classes: 'bg-green-50 text-green-700' },
  evenement: { libelle: 'Événement', classes: 'bg-amber-50 text-amber-700' },
}

const VUES_MENSUELLES = {
  mouvements: {
    libelle: 'Mouvements d’élèves',
    source: 'inscriptions_par_mois',
    series: [
      { cle: 'inscriptions', libelle: 'Inscriptions', couleur: 'var(--chart-1)' },
      { cle: 'reinscriptions', libelle: 'Réinscriptions', couleur: 'var(--chart-2)' },
      { cle: 'transferts', libelle: 'Transferts', couleur: 'var(--chart-3)' },
    ],
    vide: 'Aucune date d’inscription renseignée sur les élèves de cette année.',
  },
  absences: {
    libelle: 'Absences',
    source: 'absences_par_mois',
    series: [
      { cle: 'justifiees', libelle: 'Justifiées', couleur: 'var(--chart-2)' },
      { cle: 'non_justifiees', libelle: 'Non justifiées', couleur: 'var(--chart-3)' },
    ],
    vide: 'Aucune absence saisie pour cette année.',
  },
}

function Tuile({ icon: Icon, valeur, libelle, precision }) {
  return (
    <div className="card flex items-center gap-3 rounded-2xl p-4">
      <div className="shrink-0 rounded-xl bg-primary-50 p-2.5 text-primary-700">
        <Icon size={20} />
      </div>
      <div className="min-w-0">
        <p className="text-xl font-bold leading-tight text-heading">{valeur ?? '—'}</p>
        <p className="truncate text-xs text-muted">{libelle}</p>
        {precision && <p className="truncate text-[11px] text-muted">{precision}</p>}
      </div>
    </div>
  )
}

/**
 * Le périmètre demandé n'a pas pu s'appliquer : les chiffres affichés sont ceux de
 * toute la société. Le dire est le seul comportement acceptable — un écran à zéro
 * passerait pour une panne, et des chiffres élargis affichés en silence seraient faux.
 */
function AvertissementPerimetre({ perimetre, etablissement }) {
  if (!perimetre || perimetre.applique || perimetre.raison === 'aucun_etablissement') return null

  const orphelines = perimetre.classes_sans_etablissement

  return (
    <div className="mt-5 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
      <AlertTriangle size={18} className="mt-0.5 shrink-0" />
      <div>
        <p className="font-semibold">
          Ces chiffres couvrent toute la société, pas seulement {etablissement?.nom ?? 'cet établissement'}.
        </p>
        <p className="mt-1">
          {perimetre.raison === 'aucune_classe_rattachee'
            ? `Aucune classe de cette année scolaire ne porte le code de cet établissement${
                orphelines > 0 ? ` (${orphelines} classe${orphelines > 1 ? 's' : ''} sans rattachement)` : ''
              }. Les élèves étant rattachés à un établissement par leur classe, le filtrage reste impossible tant que ces classes n’ont pas d’établissement.`
            : 'Le référentiel des classes n’a pas pu être lu ; le filtrage par établissement est suspendu.'}
        </p>
        <Link to="/classes" className="mt-2 inline-block font-semibold underline">
          Corriger les classes
        </Link>
      </div>
    </div>
  )
}

function Agenda({ lignes }) {
  return (
    <div className="card mt-5 overflow-hidden rounded-2xl">
      <div className="flex items-center gap-2 px-5 pt-5">
        <CalendarClock size={18} className="text-muted" />
        <h2 className="text-base font-semibold text-heading">Prochaines échéances</h2>
      </div>
      {lignes.length === 0 ? (
        <p className="px-5 py-8 text-center text-sm text-muted">
          Rien de planifié pour les jours à venir.
        </p>
      ) : (
        <div className="mt-3 overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr>
                <th className="text-left">Date</th>
                <th className="text-left">Nature</th>
                <th className="text-left">Intitulé</th>
                <th className="text-left">Classe</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {lignes.map((l, i) => {
                const nature = NATURES[l.nature] ?? { libelle: l.nature, classes: 'bg-slate-100 text-slate-500' }
                return (
                  <tr key={`${l.date}-${l.titre}-${i}`}>
                    <td className="whitespace-nowrap text-slate-600">
                      {new Date(l.date).toLocaleDateString('fr-FR', {
                        day: '2-digit',
                        month: 'short',
                        year: 'numeric',
                      })}
                    </td>
                    <td>
                      <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${nature.classes}`}>
                        {nature.libelle}
                      </span>
                    </td>
                    <td className="text-slate-600">
                      {l.titre}
                      {l.type && <span className="ml-1.5 text-xs text-muted">({l.type})</span>}
                    </td>
                    <td className="text-slate-600">{l.classe ?? '—'}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

export default function DashboardPage() {
  const { user } = useAuthStore()
  const [vue, setVue] = useState('mouvements')
  const { data: stats, isLoading } = useQuery({
    queryKey: ['dashboard-stats'],
    queryFn: fetchDashboardStats,
  })

  const effectifs = stats?.effectifs ?? {}
  const vueCourante = VUES_MENSUELLES[vue]
  const donneesMensuelles = stats?.[vueCourante.source] ?? []
  const classes = stats?.moyenne_par_classe ?? []

  return (
    <div>
      {/* Bandeau d'identité : le seul aplat de marque de l'écran. */}
      <div
        className="relative overflow-hidden rounded-2xl p-6 sm:p-8"
        style={{
          background: 'linear-gradient(135deg, var(--brand-surface), var(--brand-surface-2))',
          boxShadow: 'var(--shadow)',
        }}
      >
        <div className="pointer-events-none absolute -right-10 -top-16 h-56 w-56 rounded-full bg-primary-400/30 blur-3xl" />
        <div className="pointer-events-none absolute -bottom-16 left-1/4 h-56 w-56 rounded-full bg-primary-300/20 blur-3xl" />
        <div className="relative flex flex-wrap items-end justify-between gap-4">
          <div>
            <h1 className="text-xl font-bold text-white sm:text-2xl">Bonjour, {user?.name} 👋</h1>
            <p className="mt-1 text-sm" style={{ color: 'var(--sidebar-text)' }}>
              {stats?.etablissement?.nom ?? 'Vue société'}
              {stats?.annee_scolaire_active ? ` — ${stats.annee_scolaire_active}` : ''}
            </p>
          </div>
          <div className="text-right">
            <p className="text-4xl font-extrabold leading-none text-white">
              {isLoading ? '…' : (effectifs.total_eleves ?? 0)}
            </p>
            <p className="text-xs uppercase tracking-wide" style={{ color: 'var(--sidebar-text)' }}>
              élèves inscrits
            </p>
          </div>
        </div>
      </div>

      <AvertissementPerimetre perimetre={stats?.perimetre} etablissement={stats?.etablissement} />

      <div className="mt-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <Tuile icon={Users} valeur={effectifs.total_eleves} libelle="Élèves" />
        <Tuile icon={School} valeur={effectifs.total_classes} libelle="Classes" />
        <Tuile icon={GraduationCap} valeur={effectifs.total_enseignants} libelle="Enseignants" />
        <Tuile
          icon={TrendingUp}
          valeur={stats?.moyenne_generale != null ? `${stats.moyenne_generale}/20` : null}
          libelle="Moyenne générale"
          precision={
            stats?.absences_ce_mois != null
              ? `${stats.absences_ce_mois} absence${stats.absences_ce_mois > 1 ? 's' : ''} ce mois`
              : null
          }
        />
      </div>

      <div className="mt-5 grid gap-5 lg:grid-cols-2">
        <CarteGraphique
          titre="Répartition par niveau"
          hauteur={180}
          vide={(stats?.repartition_niveaux?.length ?? 0) === 0}
          messageVide="Aucun élève rattaché à un niveau pour cette année."
        >
          <Anneau donnees={stats?.repartition_niveaux ?? []} cleValeur="effectif" cleNom="niveau" />
        </CarteGraphique>

        <CarteGraphique titre="Indicateurs clés" hauteur={180} vide={false}>
          <div className="flex h-full flex-wrap items-center justify-around gap-x-2 gap-y-4">
            <Jauge
              valeur={stats?.assiduite?.taux ?? null}
              couleur="var(--chart-2)"
              libelle="Assiduité"
              detail={
                stats?.assiduite
                  ? `${stats.assiduite.absences} absence${stats.assiduite.absences > 1 ? 's' : ''} / ${stats.assiduite.jours_ouvres} j. ouvrés`
                  : 'Dates de l’année manquantes'
              }
            />
            <Jauge
              valeur={stats?.taux_reussite?.taux ?? null}
              couleur="var(--chart-1)"
              libelle="Réussite"
              detail={
                stats?.taux_reussite
                  ? `${stats.taux_reussite.admis} / ${stats.taux_reussite.evalues} ≥ 10`
                  : 'Aucune moyenne calculée'
              }
            />
            <Jauge
              valeur={stats?.taux_encadrement?.taux ?? null}
              couleur="var(--chart-3)"
              libelle="Encadrement"
              detail={
                stats?.taux_encadrement
                  ? `${stats.taux_encadrement.couvertes} / ${stats.taux_encadrement.classes} classes`
                  : 'Aucune classe'
              }
            />
          </div>
        </CarteGraphique>
      </div>

      <div className="mt-5 grid gap-5 lg:grid-cols-3">
        <CarteGraphique
          titre="Moyenne par classe"
          hauteur={240}
          vide={classes.length === 0}
          messageVide="Aucune moyenne calculée pour cette année."
        >
          <ul className="max-h-[280px] space-y-2 overflow-y-auto pr-1">
            {classes.map((c) => (
              <li key={c.classe} className="rounded-xl surface-2 px-3 py-2">
                <div className="flex items-center justify-between text-sm">
                  <span className="min-w-0 truncate font-medium text-heading">{c.classe}</span>
                  <span
                    className={`ml-2 shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold ${
                      c.moyenne >= 10 ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'
                    }`}
                  >
                    {c.moyenne}/20
                  </span>
                </div>
                <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-200">
                  <div
                    className="h-full rounded-full"
                    style={{
                      width: `${Math.min(100, (c.moyenne / 20) * 100)}%`,
                      background: c.moyenne >= 10 ? 'var(--success)' : 'var(--danger)',
                    }}
                  />
                </div>
              </li>
            ))}
          </ul>
        </CarteGraphique>

        <div className="lg:col-span-2">
          <CarteGraphique
            titre="Évolution mensuelle"
            hauteur={280}
            hauteurFixe
            vide={donneesMensuelles.length === 0}
            messageVide={vueCourante.vide}
            actions={
              <div className="flex gap-1 rounded-lg p-1" style={{ background: 'var(--surface-2)' }}>
                {Object.entries(VUES_MENSUELLES).map(([cle, v]) => (
                  <button
                    key={cle}
                    type="button"
                    onClick={() => setVue(cle)}
                    className="rounded-md px-2.5 py-1 text-xs font-semibold transition"
                    style={
                      vue === cle
                        ? { background: 'var(--brand-accent)', color: 'var(--brand-accent-ink)' }
                        : { color: 'var(--muted-text)' }
                    }
                  >
                    {v.libelle}
                  </button>
                ))}
              </div>
            }
          >
            <HistogrammeMensuel donnees={donneesMensuelles} series={vueCourante.series} />
          </CarteGraphique>
        </div>
      </div>

      <Agenda lignes={stats?.agenda ?? []} />
    </div>
  )
}
