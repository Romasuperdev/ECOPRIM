import { useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, Download } from 'lucide-react'
import Button from '../../components/ui/Button'
import {
  fetchAbsencesEnfant,
  fetchCahierTextesEnfant,
  fetchEnfant,
  fetchMoyennesEnfant,
  telechargerBulletinEnfant,
} from './portailParentApi'

const JOURS_CAHIER = [
  { cle: 'lundi', libelle: 'Lundi' }, { cle: 'mardi', libelle: 'Mardi' },
  { cle: 'mercredi', libelle: 'Mercredi' }, { cle: 'jeudi', libelle: 'Jeudi' },
  { cle: 'vendredi', libelle: 'Vendredi' },
]

const TABS = [
  { cle: 'fiche', libelle: 'Fiche' },
  { cle: 'absences', libelle: 'Absences' },
  { cle: 'cahier', libelle: 'Cahier de textes' },
  { cle: 'resultats', libelle: 'Résultats' },
]

export default function ParentEnfantPage() {
  const { matricule } = useParams()
  const [onglet, setOnglet] = useState('fiche')
  const { data: eleve } = useQuery({ queryKey: ['portail-enfant', matricule], queryFn: () => fetchEnfant(matricule) })

  return (
    <div>
      <Link to="/mon-espace" className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted hover:text-heading">
        <ArrowLeft size={15} /> Mes enfants
      </Link>
      <h1 className="mb-1 text-2xl font-bold text-heading">{`${eleve?.prenom ?? ''} ${eleve?.nom ?? ''}`.trim() || matricule}</h1>
      <p className="mb-4 text-sm text-muted">{eleve?.classe?.nom ?? eleve?.classe_code ?? '—'}</p>

      <div className="mb-6 flex gap-1 border-b border-[var(--border)]">
        {TABS.map((t) => (
          <button
            key={t.cle} type="button" onClick={() => setOnglet(t.cle)}
            className={`px-3 py-2 text-sm font-medium ${
              onglet === t.cle ? 'border-b-2 border-[var(--accent)] text-heading' : 'text-muted hover:text-heading'
            }`}
          >
            {t.libelle}
          </button>
        ))}
      </div>

      {onglet === 'fiche' && <OngletFiche eleve={eleve} />}
      {onglet === 'absences' && <OngletAbsences matricule={matricule} />}
      {onglet === 'cahier' && <OngletCahier matricule={matricule} />}
      {onglet === 'resultats' && <OngletResultats matricule={matricule} />}
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
    <div className="overflow-hidden rounded-xl border border-[var(--border)] bg-[var(--surface)]">
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
        <div key={e.id} className="overflow-hidden rounded-xl border border-[var(--border)] bg-[var(--surface)]">
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

      <div className="overflow-hidden rounded-xl border border-[var(--border)] bg-[var(--surface)]">
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
