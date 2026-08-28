import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import Select from '../../components/ui/Select'
import { fetchEvaluations } from './rapportsApi'
import { fetchAllClasses } from '../reference/referenceApi'

const TYPE_LABELS = {
  devoir: 'Devoir',
  composition: 'Composition',
  interrogation: 'Interrogation',
}

export default function EvaluationListPage() {
  const [classeId, setClasseId] = useState('')

  const { data: classes } = useQuery({ queryKey: ['classes', 'all'], queryFn: fetchAllClasses })
  const { data: evaluations, isLoading } = useQuery({
    queryKey: ['evaluations', classeId],
    queryFn: () => fetchEvaluations(classeId || undefined),
  })

  return (
    <div>
      <h1 className="mb-2 text-2xl font-bold text-slate-800">Évaluations</h1>
      <p className="mb-6 text-sm text-slate-500">
        Vue d'ensemble des sessions de notation déjà saisies (regroupées par matière, classe, date et type).
      </p>

      <div className="mb-6 max-w-xs">
        <Select label="Filtrer par classe" value={classeId} onChange={(e) => setClasseId(e.target.value)}>
          <option value="">Toutes les classes</option>
          {classes?.map((classe) => (
            <option key={classe.id} value={classe.id}>
              {classe.nom}
            </option>
          ))}
        </Select>
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Date</th>
              <th className="px-4 py-3 font-medium">Classe</th>
              <th className="px-4 py-3 font-medium">Matière</th>
              <th className="px-4 py-3 font-medium">Type</th>
              <th className="px-4 py-3 font-medium">Notes saisies</th>
              <th className="px-4 py-3 font-medium">Moyenne</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr>
                <td colSpan={6} className="px-4 py-6 text-center text-slate-400">
                  Chargement…
                </td>
              </tr>
            )}
            {!isLoading && evaluations?.length === 0 && (
              <tr>
                <td colSpan={6} className="px-4 py-6 text-center text-slate-400">
                  Aucune évaluation pour l'instant.
                </td>
              </tr>
            )}
            {evaluations?.map((evaluation, index) => (
              <tr key={index} className="hover:bg-slate-50">
                <td className="px-4 py-3 text-slate-600">{evaluation.date_evaluation}</td>
                <td className="px-4 py-3 font-medium text-slate-800">{evaluation.classe}</td>
                <td className="px-4 py-3 text-slate-600">{evaluation.matiere}</td>
                <td className="px-4 py-3 text-slate-600">
                  {TYPE_LABELS[evaluation.type_evaluation] ?? evaluation.type_evaluation}
                </td>
                <td className="px-4 py-3 text-slate-600">{evaluation.nombre_notes}</td>
                <td className="px-4 py-3">
                  <span
                    className={`rounded-full px-2 py-0.5 text-xs font-semibold ${
                      evaluation.moyenne >= 10 ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'
                    }`}
                  >
                    {evaluation.moyenne}/20
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
