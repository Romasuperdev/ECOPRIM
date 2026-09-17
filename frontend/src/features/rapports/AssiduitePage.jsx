import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CalendarCheck } from 'lucide-react'
import Select from '../../components/ui/Select'
import PastilleRelief from '../../components/ui/PastilleRelief'
import { fetchAssiduiteClasse } from './rapportsApi'
import { fetchAllClasses } from '../reference/referenceApi'

/**
 * Assiduité par classe : pour chaque élève, ses absences depuis la rentrée et son taux
 * de présence. Contrepartie « synthèse » de la page Absences, où l'on saisit au jour le
 * jour.
 *
 * Il n'y a volontairement pas de colonne « Retards » : la table des absences d'ECONOMAT
 * (T_ABSENCEELEVE) n'a aucune notion de retard — matricule, classe, date, heure, cause,
 * justifié. La colonne existait et restait donc désespérément vide.
 */
function Tuile({ valeur, libelle }) {
  return (
    <div className="card flex-1 rounded-2xl px-4 py-3">
      <p className="text-2xl font-extrabold leading-tight text-heading">{valeur}</p>
      <p className="text-sm font-semibold text-muted">{libelle}</p>
    </div>
  )
}

export default function AssiduitePage() {
  const [classeId, setClasseId] = useState('')

  const { data: classes } = useQuery({ queryKey: ['classes', 'all'], queryFn: fetchAllClasses })

  const { data: rapport, isLoading } = useQuery({
    queryKey: ['assiduite', classeId],
    queryFn: () => fetchAssiduiteClasse(classeId),
    enabled: Boolean(classeId),
  })

  return (
    <div>
      <div className="mb-6 flex items-center gap-3">
        <PastilleRelief icon={CalendarCheck} module="traitement" taille="md" />
        <div>
          <h1 className="text-2xl font-bold text-heading">Assiduité</h1>
          <p className="text-sm font-medium text-muted">
            Absences de chaque élève depuis la rentrée, classe par classe.
          </p>
        </div>
      </div>

      <div className="mb-6 max-w-xs">
        <Select label="Classe" value={classeId} onChange={(e) => setClasseId(e.target.value)}>
          <option value="">— Sélectionner —</option>
          {classes?.map((classe) => (
            <option key={classe.id} value={classe.id}>
              {classe.nom}
            </option>
          ))}
        </Select>
      </div>

      {!classeId && (
        <p className="card rounded-2xl px-4 py-10 text-center text-sm font-medium text-muted">
          Choisissez une classe pour voir l’assiduité de ses élèves.
        </p>
      )}
      {isLoading && <p className="font-medium text-muted">Calcul en cours…</p>}

      {rapport && (
        <>
          <div className="mb-5 flex flex-wrap gap-4">
            <Tuile valeur={rapport.effectif ?? 0} libelle="Élèves" />
            <Tuile valeur={rapport.total_absences ?? 0} libelle="Absences cumulées" />
            <Tuile valeur={rapport.jours_ouvres ?? '—'} libelle="Jours ouvrés écoulés" />
          </div>

          <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-card">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-slate-200 bg-slate-50">
                <tr>
                  <th className="px-4 py-3 font-bold text-heading">Élève</th>
                  <th className="px-4 py-3 font-bold text-heading">Absences</th>
                  <th className="px-4 py-3 font-bold text-heading">Non justifiées</th>
                  <th className="px-4 py-3 font-bold text-heading">Taux de présence</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rapport.eleves.length === 0 && (
                  <tr>
                    <td colSpan={4} className="px-4 py-6 text-center text-sm font-medium text-muted">
                      Aucun élève inscrit dans cette classe pour cette année.
                    </td>
                  </tr>
                )}
                {rapport.eleves.map((eleve) => (
                  <tr key={eleve.matricule} className="hover:bg-slate-50">
                    <td className="px-4 py-3 font-semibold text-heading">
                      {eleve.prenom} {eleve.nom}
                    </td>
                    <td className="px-4 py-3 font-medium text-heading">{eleve.total_absences}</td>
                    <td className="px-4 py-3">
                      <span
                        className={`rounded-full px-2 py-0.5 text-xs font-bold ${
                          eleve.absences_non_justifiees > 0
                            ? 'bg-amber-100 text-amber-800'
                            : 'bg-slate-100 text-slate-700'
                        }`}
                      >
                        {eleve.absences_non_justifiees}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      {eleve.taux_presence == null ? (
                        <span className="text-sm font-medium text-muted">—</span>
                      ) : (
                        <span
                          className={`rounded-full px-2 py-0.5 text-xs font-bold ${
                            eleve.taux_presence >= 90
                              ? 'bg-green-100 text-green-800'
                              : 'bg-red-100 text-red-800'
                          }`}
                        >
                          {eleve.taux_presence}%
                        </span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {rapport.jours_ouvres && (
            <p className="mt-3 text-xs font-medium text-muted">
              Le taux de présence rapporte les absences aux {rapport.jours_ouvres} jours ouvrés
              écoulés depuis la rentrée, une absence comptant pour une journée. Les vacances ne
              sont pas déduites : le taux réel est donc légèrement inférieur.
            </p>
          )}
        </>
      )}
    </div>
  )
}
