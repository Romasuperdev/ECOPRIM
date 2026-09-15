import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { CalendarDays, ChevronRight, ClipboardList, Star } from 'lucide-react'
import {
  fetchMesClasses,
  fetchMesDevoirs,
  fetchMesEvenements,
  fetchProchainsCours,
} from './portailEnseignantApi'

const TYPE_LIBELLES = {
  vacances: 'Congés / Vacances',
  reunion: 'Réunion parents-professeurs',
  sortie: 'Sortie pédagogique',
  activite: 'Activité scolaire',
}

function SectionProchainsCours() {
  const { data, isLoading } = useQuery({ queryKey: ['mes-prochains-cours'], queryFn: fetchProchainsCours })

  return (
    <section className="card p-5">
      <h2 className="mb-3 flex items-center gap-1.5 text-sm font-semibold text-heading">
        <ClipboardList size={16} /> Cette semaine
      </h2>
      {isLoading && <p className="text-sm text-muted">Chargement…</p>}
      {!isLoading && (data ?? []).length === 0 && <p className="text-sm text-muted">Aucun cours cette semaine.</p>}
      <ul className="space-y-1.5">
        {(data ?? []).map((c, i) => (
          <li key={i} className="flex items-center justify-between text-sm">
            <span className="text-heading">{c.jour_libelle} — {c.matiere_libelle}</span>
            <span className="text-xs text-muted">{c.classe_libelle} · {c.heure_libelle}</span>
          </li>
        ))}
      </ul>
    </section>
  )
}

function SectionDevoirs() {
  const { data, isLoading } = useQuery({ queryKey: ['mes-devoirs-accueil'], queryFn: () => fetchMesDevoirs() })

  return (
    <section className="card p-5">
      <h2 className="mb-3 flex items-center gap-1.5 text-sm font-semibold text-heading">
        <ClipboardList size={16} /> Prochains devoirs
      </h2>
      {isLoading && <p className="text-sm text-muted">Chargement…</p>}
      {!isLoading && (data ?? []).length === 0 && <p className="text-sm text-muted">Aucun devoir en cours.</p>}
      <ul className="space-y-1.5">
        {(data ?? []).slice(0, 5).map((d) => (
          <li key={d.id} className="flex items-center justify-between text-sm">
            <span className="text-heading">{d.titre}</span>
            <span className="text-xs text-muted">{d.classe_libelle} · {d.date_remise}</span>
          </li>
        ))}
      </ul>
    </section>
  )
}

function SectionEvenements() {
  const { data, isLoading } = useQuery({ queryKey: ['mes-evenements-accueil'], queryFn: fetchMesEvenements })

  return (
    <section className="card p-5">
      <h2 className="mb-3 flex items-center gap-1.5 text-sm font-semibold text-heading">
        <CalendarDays size={16} /> Calendrier scolaire
      </h2>
      {isLoading && <p className="text-sm text-muted">Chargement…</p>}
      {!isLoading && (data ?? []).length === 0 && <p className="text-sm text-muted">Aucun événement à venir.</p>}
      <ul className="space-y-1.5">
        {(data ?? []).slice(0, 5).map((e) => (
          <li key={e.id} className="flex items-center justify-between text-sm">
            <span className="text-heading">{e.titre}</span>
            <span className="text-xs text-muted">{TYPE_LIBELLES[e.type] ?? e.type} · {e.date_debut}</span>
          </li>
        ))}
      </ul>
    </section>
  )
}

/** Accueil du portail Enseignant : mes classes, mon emploi du temps, mes devoirs, le calendrier. */
export default function EnseignantAccueilPage() {
  const { data, isLoading } = useQuery({ queryKey: ['mes-classes'], queryFn: fetchMesClasses })

  const classes = data?.classes ?? []

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-heading">Mes classes</h1>
        <p className="mt-1 text-sm text-muted">
          Année {data?.annee ?? 'en cours'} — cahier de textes, absences et emploi du temps de
          vos classes.
        </p>
      </div>

      {isLoading && <p className="text-muted">Chargement…</p>}

      {!isLoading && classes.length === 0 && (
        <p className="card px-4 py-10 text-center text-sm text-muted">
          Aucune classe ne vous est encore affectée. Rapprochez-vous de l'administration.
        </p>
      )}

      <div className="grid gap-3 sm:grid-cols-2">
        {classes.map((c) => (
          <Link
            key={`${c.classe}-${c.matiere}`}
            to={`/mon-espace/classes/${c.classe}`}
            className="card flex items-center justify-between px-4 py-4 transition hover:shadow-md"
          >
            <div>
              <div className="flex items-center gap-1.5 font-semibold text-heading">
                {c.classe_libelle ?? c.classe}
                {c.principale && <Star size={14} className="text-amber-500" fill="currentColor" />}
              </div>
              <div className="text-sm text-muted">{c.matiere_libelle ?? c.matiere}</div>
            </div>
            <ChevronRight size={18} className="text-muted" />
          </Link>
        ))}
      </div>

      {classes.length > 0 && (
        <div className="mt-6 grid gap-4 md:grid-cols-3">
          <SectionProchainsCours />
          <SectionDevoirs />
          <SectionEvenements />
        </div>
      )}
    </div>
  )
}
