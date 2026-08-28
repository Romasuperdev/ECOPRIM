import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import Select from '../../components/ui/Select'
import { fetchAssiduiteClasse } from './rapportsApi'
import { fetchAllClasses } from '../reference/referenceApi'

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
      <h1 className="mb-6 text-2xl font-bold text-slate-800">Assiduité</h1>

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

      {!classeId && <p className="text-slate-400">Sélectionne une classe pour voir l'assiduité.</p>}
      {isLoading && <p className="text-slate-400">Calcul en cours…</p>}

      {rapport && (
        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
              <tr>
                <th className="px-4 py-3 font-medium">Élève</th>
                <th className="px-4 py-3 font-medium">Total absences</th>
                <th className="px-4 py-3 font-medium">Non justifiées</th>
                <th className="px-4 py-3 font-medium">Retards</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {rapport.eleves.length === 0 && (
                <tr>
                  <td colSpan={4} className="px-4 py-6 text-center text-slate-400">
                    Aucun élève dans cette classe.
                  </td>
                </tr>
              )}
              {rapport.eleves.map((eleve) => (
                <tr key={eleve.eleve_id} className="hover:bg-slate-50">
                  <td className="px-4 py-3 font-medium text-slate-800">
                    {eleve.prenom} {eleve.nom}
                  </td>
                  <td className="px-4 py-3 text-slate-600">{eleve.total_absences}</td>
                  <td className="px-4 py-3">
                    <span
                      className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                        eleve.absences_non_justifiees > 0 ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-500'
                      }`}
                    >
                      {eleve.absences_non_justifiees}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-slate-600">{eleve.total_retards}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
