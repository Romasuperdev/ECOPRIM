import { useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import BandeauPortail from '../../components/ui/BandeauPortail'
import Onglets from '../../components/ui/Onglets'
import { ArrowLeft, Download, FileText } from 'lucide-react'
import Button from '../../components/ui/Button'
import {
  fetchAbsencesEnfant,
  fetchCahierTextesEnfant,
  fetchDevoirsEnfant,
  fetchEmploiDuTempsEnfant,
  fetchEmploiReferentiels,
  fetchEnfant,
  fetchEvaluationsPlanifieesEnfant,
  fetchEvenementsEnfant,
  fetchMoyennesEnfant,
  telechargerAttestationFrequentation,
  telechargerBulletinEnfant,
  telechargerCertificatScolarite,
} from './portailParentApi'

const TYPE_EVENEMENT_LIBELLES = {
  vacances: 'Congés / Vacances',
  reunion: 'Réunion parents-professeurs',
  sortie: 'Sortie pédagogique',
  activite: 'Activité scolaire',
}

const JOURS_CAHIER = [
  { cle: 'lundi', libelle: 'Lundi' }, { cle: 'mardi', libelle: 'Mardi' },
  { cle: 'mercredi', libelle: 'Mercredi' }, { cle: 'jeudi', libelle: 'Jeudi' },
  { cle: 'vendredi', libelle: 'Vendredi' },
]

const TABS = [
  { cle: 'fiche', libelle: 'Fiche' },
  { cle: 'emploi', libelle: 'Emploi du temps' },
  { cle: 'devoirs', libelle: 'Devoirs' },
  { cle: 'evaluations', libelle: 'Évaluations' },
  { cle: 'absences', libelle: 'Absences' },
  { cle: 'cahier', libelle: 'Cahier journal' },
  { cle: 'resultats', libelle: 'Résultats' },
  { cle: 'calendrier', libelle: 'Calendrier' },
  { cle: 'documents', libelle: 'Documents' },
]

export default function ParentEnfantPage() {
  const { matricule } = useParams()
  const [onglet, setOnglet] = useState('fiche')
  const { data: eleve } = useQuery({ queryKey: ['portail-enfant', matricule], queryFn: () => fetchEnfant(matricule) })

  return (
    <div>
      <Link
        to="/mon-espace"
        className="mb-4 inline-flex items-center gap-1.5 text-sm font-bold text-muted hover:text-heading"
      >
        <ArrowLeft size={15} strokeWidth={2.5} /> Mes enfants
      </Link>

      <div className="mb-5">
        <BandeauPortail
          titre={`${eleve?.prenom ?? ''} ${eleve?.nom ?? ''}`.trim() || matricule}
          sousTitre={eleve?.classe?.nom ?? eleve?.classe_code ?? '—'}
        />
      </div>

      <Onglets onglets={TABS} actif={onglet} onChanger={setOnglet} />

      {onglet === 'fiche' && <OngletFiche eleve={eleve} />}
      {onglet === 'emploi' && <OngletEmploi matricule={matricule} />}
      {onglet === 'devoirs' && <OngletDevoirs matricule={matricule} />}
      {onglet === 'evaluations' && <OngletEvaluations matricule={matricule} />}
      {onglet === 'absences' && <OngletAbsences matricule={matricule} />}
      {onglet === 'cahier' && <OngletCahier matricule={matricule} />}
      {onglet === 'resultats' && <OngletResultats matricule={matricule} />}
      {onglet === 'calendrier' && <OngletCalendrier matricule={matricule} />}
      {onglet === 'documents' && <OngletDocuments matricule={matricule} />}
    </div>
  )
}

function Champ({ label, valeur }) {
  return (
    <div>
      <div className="text-xs font-medium text-muted">{label}</div>
      <div className="text-sm text-heading">{valeur || '—'}</div>
    </div>
  )
}

function OngletFiche({ eleve }) {
  if (! eleve) return <p className="text-muted">Chargement…</p>

  return (
    <div className="card grid grid-cols-2 gap-4 p-5 sm:grid-cols-3">
      <Champ label="Matricule" valeur={eleve.matricule} />
      <Champ label="Sexe" valeur={eleve.sexe} />
      <Champ label="Date de naissance" valeur={eleve.date_naissance} />
      <Champ label="Classe" valeur={eleve.classe?.nom ?? eleve.classe_code} />
      <Champ label="Enseignant titulaire" valeur={eleve.enseignant_titulaire} />
      <Champ label="Père / Tuteur" valeur={`${eleve.pere_prenom ?? ''} ${eleve.pere_nom ?? ''}`.trim()} />
      <Champ label="Téléphone (père/tuteur)" valeur={eleve.pere_telephone} />
      <Champ label="Mère" valeur={`${eleve.mere_prenom ?? ''} ${eleve.mere_nom ?? ''}`.trim()} />
      <Champ label="Téléphone (mère)" valeur={eleve.mere_telephone} />
    </div>
  )
}

function OngletAbsences({ matricule }) {
  const { data: absences, isLoading } = useQuery({ queryKey: ['portail-absences-enfant', matricule], queryFn: () => fetchAbsencesEnfant(matricule) })

  return (
    <div className="overflow-x-auto rounded-xl border border-[var(--border)] bg-[var(--surface)]">
      <table className="w-full text-left text-sm">
        <thead className="border-b" style={{ borderColor: 'var(--border)' }}>
          <tr className="text-muted">
            <th className="px-4 py-3 font-medium">Date</th>
            <th className="px-4 py-3 font-medium">Motif</th>
            <th className="px-4 py-3 font-medium">Justifiée</th>
          </tr>
        </thead>
        <tbody className="divide-y" style={{ borderColor: 'var(--border)' }}>
          {isLoading && <tr><td colSpan={3} className="px-4 py-6 text-center text-muted">Chargement…</td></tr>}
          {!isLoading && (absences?.length ?? 0) === 0 && (
            <tr><td colSpan={3} className="px-4 py-6 text-center text-muted">Aucune absence.</td></tr>
          )}
          {absences?.map((a) => (
            <tr key={a.id}>
              <td className="px-4 py-3 text-heading">{a.date ?? '—'}</td>
              <td className="px-4 py-3 text-muted">{a.motif ?? '—'}</td>
              <td className="px-4 py-3 text-muted">{a.justifiee ? 'Oui' : 'Non'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function OngletCahier({ matricule }) {
  const { data, isLoading } = useQuery({ queryKey: ['portail-cahier-enfant', matricule], queryFn: () => fetchCahierTextesEnfant(matricule) })
  const entetes = data?.entetes ?? []

  if (isLoading) return <p className="text-muted">Chargement…</p>
  if (entetes.length === 0) return <p className="card px-4 py-10 text-center text-sm text-muted">Aucune semaine enregistrée.</p>

  return (
    <div className="space-y-4">
      {entetes.map((e) => (
        <div key={e.id} className="overflow-x-auto rounded-xl border border-[var(--border)] bg-[var(--surface)]">
          <div className="border-b px-4 py-2 text-sm font-semibold text-heading" style={{ borderColor: 'var(--border)' }}>
            {e.mois} — semaine {e.semaine}
          </div>
          <table className="w-full border-collapse text-sm">
            <thead>
              <tr className="text-muted">
                <th className="px-3 py-2 text-left font-medium">Matière</th>
                {JOURS_CAHIER.map((j) => <th key={j.cle} className="px-1.5 py-2 text-left font-medium">{j.libelle}</th>)}
              </tr>
            </thead>
            <tbody className="divide-y" style={{ borderColor: 'var(--border)' }}>
              {e.lignes.map((l) => (
                <tr key={l.matiere ?? l.id}>
                  <td className="whitespace-nowrap px-3 py-2 font-medium text-heading">{l.matiere_libelle}</td>
                  {JOURS_CAHIER.map((j) => <td key={j.cle} className="px-1.5 py-2 text-muted">{l[j.cle] || '—'}</td>)}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ))}
    </div>
  )
}

/** Grille jour × heure, même présentation que le portail Enseignant (lecture seule). */
function OngletEmploi({ matricule }) {
  const { data: ref } = useQuery({ queryKey: ['portail-emploi-ref-parent'], queryFn: fetchEmploiReferentiels })
  const { data, isLoading } = useQuery({ queryKey: ['portail-emploi-enfant', matricule], queryFn: () => fetchEmploiDuTempsEnfant(matricule) })

  const jours = ref?.jours ?? []
  const heures = ref?.heures ?? []
  const creneauDe = (jour, heure) => data?.creneaux?.find((c) => c.jour === jour && c.heure === heure)

  if (isLoading) return <p className="text-muted">Chargement…</p>
  if (jours.length === 0 || heures.length === 0) {
    return <p className="card px-4 py-10 text-center text-sm text-muted">Aucun emploi du temps disponible.</p>
  }

  return (
    <div className="overflow-x-auto rounded-xl border border-[var(--border)] bg-[var(--surface)]">
      <table className="w-full border-collapse text-sm">
        <thead>
          <tr className="text-muted">
            <th className="border-b border-r px-3 py-2 text-left font-medium" style={{ borderColor: 'var(--border)' }}>Horaire</th>
            {jours.map((j) => <th key={j.code} className="border-b px-3 py-2 text-left font-medium" style={{ borderColor: 'var(--border)' }}>{j.libelle}</th>)}
          </tr>
        </thead>
        <tbody>
          {heures.map((h) => (
            <tr key={h.code}>
              <th className="whitespace-nowrap border-b border-r px-3 py-2 text-left text-xs font-medium text-muted" style={{ borderColor: 'var(--border)' }}>
                {h.libelle || h.code}
              </th>
              {jours.map((j) => {
                const c = creneauDe(j.code, h.code)
                return (
                  <td key={j.code} className="border-b p-1.5 align-top" style={{ borderColor: 'var(--border)' }}>
                    {c && (
                      <div className="rounded-lg bg-[var(--brand-accent)]/10 px-2 py-1.5">
                        <div className="text-xs font-semibold text-heading">{c.matiere_libelle}</div>
                        {c.salle_libelle && <div className="text-[11px] text-muted">{c.salle_libelle}</div>}
                      </div>
                    )}
                  </td>
                )
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function OngletDevoirs({ matricule }) {
  const { data: devoirs, isLoading } = useQuery({ queryKey: ['portail-devoirs-enfant', matricule], queryFn: () => fetchDevoirsEnfant(matricule) })

  return (
    <div className="overflow-x-auto rounded-xl border border-[var(--border)] bg-[var(--surface)]">
      <table className="w-full text-left text-sm">
        <thead className="border-b" style={{ borderColor: 'var(--border)' }}>
          <tr className="text-muted">
            <th className="px-4 py-3 font-medium">Titre</th>
            <th className="px-4 py-3 font-medium">Matière</th>
            <th className="px-4 py-3 font-medium">À rendre le</th>
          </tr>
        </thead>
        <tbody className="divide-y" style={{ borderColor: 'var(--border)' }}>
          {isLoading && <tr><td colSpan={3} className="px-4 py-6 text-center text-muted">Chargement…</td></tr>}
          {!isLoading && (devoirs?.length ?? 0) === 0 && (
            <tr><td colSpan={3} className="px-4 py-6 text-center text-muted">Aucun devoir en cours.</td></tr>
          )}
          {devoirs?.map((d) => (
            <tr key={d.id}>
              <td className="px-4 py-3 font-medium text-heading">{d.titre}</td>
              <td className="px-4 py-3 text-muted">{d.matiere_libelle}</td>
              <td className="px-4 py-3 text-muted">{d.date_remise}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function OngletEvaluations({ matricule }) {
  const { data: evaluations, isLoading } = useQuery({ queryKey: ['portail-evaluations-enfant', matricule], queryFn: () => fetchEvaluationsPlanifieesEnfant(matricule) })

  return (
    <div className="overflow-x-auto rounded-xl border border-[var(--border)] bg-[var(--surface)]">
      <table className="w-full text-left text-sm">
        <thead className="border-b" style={{ borderColor: 'var(--border)' }}>
          <tr className="text-muted">
            <th className="px-4 py-3 font-medium">Titre</th>
            <th className="px-4 py-3 font-medium">Matière</th>
            <th className="px-4 py-3 font-medium">Type</th>
            <th className="px-4 py-3 font-medium">Date</th>
          </tr>
        </thead>
        <tbody className="divide-y" style={{ borderColor: 'var(--border)' }}>
          {isLoading && <tr><td colSpan={4} className="px-4 py-6 text-center text-muted">Chargement…</td></tr>}
          {!isLoading && (evaluations?.length ?? 0) === 0 && (
            <tr><td colSpan={4} className="px-4 py-6 text-center text-muted">Aucune évaluation planifiée.</td></tr>
          )}
          {evaluations?.map((e) => (
            <tr key={e.id}>
              <td className="px-4 py-3 font-medium text-heading">{e.titre}</td>
              <td className="px-4 py-3 text-muted">{e.matiere_libelle}</td>
              <td className="px-4 py-3 text-muted">{e.type}</td>
              <td className="px-4 py-3 text-muted">{e.date}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function OngletCalendrier({ matricule }) {
  const { data: evenements, isLoading } = useQuery({ queryKey: ['portail-evenements-enfant', matricule], queryFn: () => fetchEvenementsEnfant(matricule) })

  return (
    <div className="overflow-x-auto rounded-xl border border-[var(--border)] bg-[var(--surface)]">
      <table className="w-full text-left text-sm">
        <thead className="border-b" style={{ borderColor: 'var(--border)' }}>
          <tr className="text-muted">
            <th className="px-4 py-3 font-medium">Titre</th>
            <th className="px-4 py-3 font-medium">Type</th>
            <th className="px-4 py-3 font-medium">Période</th>
            <th className="px-4 py-3 font-medium">Lieu</th>
          </tr>
        </thead>
        <tbody className="divide-y" style={{ borderColor: 'var(--border)' }}>
          {isLoading && <tr><td colSpan={4} className="px-4 py-6 text-center text-muted">Chargement…</td></tr>}
          {!isLoading && (evenements?.length ?? 0) === 0 && (
            <tr><td colSpan={4} className="px-4 py-6 text-center text-muted">Aucun événement à venir.</td></tr>
          )}
          {evenements?.map((e) => (
            <tr key={e.id}>
              <td className="px-4 py-3 font-medium text-heading">{e.titre}</td>
              <td className="px-4 py-3 text-muted">{TYPE_EVENEMENT_LIBELLES[e.type] ?? e.type}</td>
              <td className="px-4 py-3 text-muted">
                {e.date_debut}{e.date_fin !== e.date_debut ? ` → ${e.date_fin}` : ''}
              </td>
              <td className="px-4 py-3 text-muted">{e.lieu ?? '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function OngletDocuments({ matricule }) {
  return (
    <div className="card flex flex-col items-start gap-3 p-5">
      <p className="text-sm text-muted">Documents administratifs téléchargeables à tout moment.</p>
      <Button variant="outline" onClick={() => telechargerCertificatScolarite(matricule)}>
        <FileText size={15} className="mr-1.5 inline" /> Certificat de scolarité
      </Button>
      <Button variant="outline" onClick={() => telechargerAttestationFrequentation(matricule)}>
        <FileText size={15} className="mr-1.5 inline" /> Attestation de fréquentation
      </Button>
      <Button variant="outline" onClick={() => telechargerBulletinEnfant(matricule)}>
        <Download size={15} className="mr-1.5 inline" /> Bulletin PDF
      </Button>
    </div>
  )
}

function OngletResultats({ matricule }) {
  const { data } = useQuery({ queryKey: ['portail-moyennes-enfant', matricule], queryFn: () => fetchMoyennesEnfant(matricule) })

  return (
    <div className="space-y-4">
      <div className="card grid grid-cols-3 gap-4 p-5 text-center">
        <div>
          <div className="text-2xl font-bold text-heading">{data?.moyenne_generale ?? '—'}</div>
          <div className="text-xs text-muted">Moyenne générale</div>
        </div>
        <div>
          <div className="text-2xl font-bold text-heading">{data?.rang ?? '—'}</div>
          <div className="text-xs text-muted">Rang / {data?.effectif ?? '—'}</div>
        </div>
        <div className="flex items-center justify-center">
          <Button variant="outline" onClick={() => telechargerBulletinEnfant(matricule)}>
            <Download size={15} className="mr-1.5 inline" /> Bulletin PDF
          </Button>
        </div>
      </div>

      <div className="overflow-x-auto rounded-xl border border-[var(--border)] bg-[var(--surface)]">
        <table className="w-full text-left text-sm">
          <thead className="border-b" style={{ borderColor: 'var(--border)' }}>
            <tr className="text-muted">
              <th className="px-4 py-3 font-medium">Matière</th>
              <th className="px-4 py-3 font-medium">Coefficient</th>
              <th className="px-4 py-3 font-medium">Moyenne</th>
              <th className="px-4 py-3 font-medium">Notes</th>
            </tr>
          </thead>
          <tbody className="divide-y" style={{ borderColor: 'var(--border)' }}>
            {(data?.par_matiere?.length ?? 0) === 0 && (
              <tr><td colSpan={4} className="px-4 py-6 text-center text-muted">Aucune note.</td></tr>
            )}
            {data?.par_matiere?.map((m) => (
              <tr key={m.matiere_code}>
                <td className="px-4 py-3 font-medium text-heading">{m.matiere}</td>
                <td className="px-4 py-3 text-muted">{m.coefficient ?? '—'}</td>
                <td className="px-4 py-3 text-muted">{m.moyenne ?? '—'}</td>
                <td className="px-4 py-3 text-muted">{m.nombre_notes}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
