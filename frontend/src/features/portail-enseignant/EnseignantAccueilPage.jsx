import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { BookOpen, CalendarDays, ChevronRight, ClipboardList, Star, Users } from 'lucide-react'
import BandeauPortail from '../../components/ui/BandeauPortail'
import PastilleRelief from '../../components/ui/PastilleRelief'
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

/**
 * Bloc de liste commun aux trois sections du bas. Il porte un état vide explicite :
 * une section sans contenu doit dire pourquoi, pas rester muette.
 */
function Section({ icon, module, titre, lignes, isLoading, messageVide }) {
  return (
    <section className="card flex flex-col rounded-2xl p-5">
      <h2 className="mb-4 flex items-center gap-2.5 text-base font-bold text-heading">
        <PastilleRelief icon={icon} module={module} taille="sm" />
        {titre}
      </h2>
      {isLoading ? (
        <p className="text-sm font-medium text-muted">Chargement…</p>
      ) : lignes.length === 0 ? (
        <p className="flex flex-1 items-center rounded-xl border border-dashed px-3 py-4 text-sm font-medium text-muted">
          {messageVide}
        </p>
      ) : (
        <ul className="space-y-2.5">
          {lignes.map((l) => (
            <li key={l.cle} className="flex items-start justify-between gap-3 text-sm">
              <span className="min-w-0 font-semibold text-heading">{l.principal}</span>
              <span className="shrink-0 text-right text-xs font-semibold text-muted">{l.secondaire}</span>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}

function SectionProchainsCours() {
  const { data, isLoading } = useQuery({ queryKey: ['mes-prochains-cours'], queryFn: fetchProchainsCours })

  return (
    <Section
      icon={CalendarDays}
      module="programme"
      titre="Cette semaine"
      isLoading={isLoading}
      messageVide="Aucun cours programmé cette semaine."
      lignes={(data ?? []).map((c, i) => ({
        cle: `${c.jour_libelle}-${c.heure_libelle}-${i}`,
        principal: `${c.jour_libelle} — ${c.matiere_libelle}`,
        secondaire: `${c.classe_libelle} · ${c.heure_libelle}`,
      }))}
    />
  )
}

function SectionDevoirs() {
  const { data, isLoading } = useQuery({ queryKey: ['mes-devoirs-accueil'], queryFn: () => fetchMesDevoirs() })

  return (
    <Section
      icon={ClipboardList}
      module="traitement"
      titre="Prochains devoirs"
      isLoading={isLoading}
      messageVide="Aucun devoir en cours."
      lignes={(data ?? []).slice(0, 5).map((d) => ({
        cle: d.id,
        principal: d.titre,
        secondaire: `${d.classe_libelle} · ${d.date_remise}`,
      }))}
    />
  )
}

function SectionEvenements() {
  const { data, isLoading } = useQuery({ queryKey: ['mes-evenements-accueil'], queryFn: fetchMesEvenements })

  return (
    <Section
      icon={CalendarDays}
      module="programme"
      titre="Calendrier scolaire"
      isLoading={isLoading}
      messageVide="Aucun événement à venir."
      lignes={(data ?? []).slice(0, 5).map((e) => ({
        cle: e.id,
        principal: e.titre,
        secondaire: `${TYPE_LIBELLES[e.type] ?? e.type} · ${e.date_debut}`,
      }))}
    />
  )
}

/** Accueil du portail Enseignant : mes classes, mon emploi du temps, mes devoirs, le calendrier. */
export default function EnseignantAccueilPage() {
  const { data, isLoading } = useQuery({ queryKey: ['mes-classes'], queryFn: fetchMesClasses })

  const classes = data?.classes ?? []
  // Une classe peut apparaître plusieurs fois (une ligne par matière enseignée) :
  // l'effectif compte les élèves, pas les affectations.
  const eleves = [...new Map(classes.map((c) => [c.classe, c.effectif ?? 0])).values()]
    .reduce((somme, n) => somme + n, 0)

  return (
    <div className="space-y-5">
      <BandeauPortail
        titre="Mes classes"
        sousTitre={`Année ${data?.annee ?? 'en cours'} — cahier de textes, notes, absences et emploi du temps.`}
        droite={
          classes.length > 0 && (
            <>
              <p className="text-3xl font-extrabold leading-none text-white">{eleves}</p>
              <p className="text-xs font-bold uppercase tracking-wide" style={{ color: 'var(--sidebar-text)' }}>
                élèves suivis
              </p>
            </>
          )
        }
      />

      {isLoading && <p className="font-medium text-muted">Chargement…</p>}

      {!isLoading && classes.length === 0 && (
        <p className="card rounded-2xl px-4 py-10 text-center text-sm font-medium text-muted">
          Aucune classe ne vous est encore affectée. Rapprochez-vous de l’administration.
        </p>
      )}

      <div className="grid gap-4 sm:grid-cols-2">
        {classes.map((c) => (
          <Link
            key={`${c.classe}-${c.matiere}`}
            to={`/mon-espace/classes/${c.classe}`}
            className="card flex items-center gap-3.5 rounded-2xl p-4 transition hover:shadow-md"
          >
            <PastilleRelief icon={BookOpen} module="traitement" taille="lg" />
            <div className="min-w-0 flex-1">
              <div className="flex items-center gap-1.5">
                <span className="truncate text-base font-bold text-heading">
                  {c.classe_libelle ?? c.classe}
                </span>
                {c.principale && (
                  <Star size={15} className="shrink-0 text-amber-500" fill="currentColor" title="Classe principale" />
                )}
              </div>
              <div className="truncate text-sm font-semibold text-muted">
                {c.matiere_libelle ?? c.matiere}
              </div>
              {c.effectif != null && (
                <div className="mt-1 inline-flex items-center gap-1 text-xs font-bold text-heading">
                  <Users size={13} /> {c.effectif} élève{c.effectif > 1 ? 's' : ''}
                </div>
              )}
            </div>
            <ChevronRight size={20} strokeWidth={2.5} className="shrink-0 text-muted" />
          </Link>
        ))}
      </div>

      {classes.length > 0 && (
        <div className="grid gap-4 md:grid-cols-3">
          <SectionProchainsCours />
          <SectionDevoirs />
          <SectionEvenements />
        </div>
      )}
    </div>
  )
}
