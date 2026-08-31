import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import Select from '../../components/ui/Select'
import apiClient from '../../api/client'
import { fetchAllClasses, fetchMatieres } from '../reference/referenceApi'

async function fetchNotesConsultation({ classe_code, matiere_code }) {
  const { data } = await apiClient.get('/notes', { params: { classe_code, matiere_code } })
  return data
}

// Lecture seule : notes issues d'ECONOMAT (V_NOTECLASSE).
export default function NoteListPage() {
  const [classeCode, setClasseCode] = useState('')
  const [matiereCode, setMatiereCode] = useState('')

  const { data: classes } = useQuery({ queryKey: ['classes', 'all'], queryFn: fetchAllClasses })
  const { data: matieres } = useQuery({ queryKey: ['matieres', 'all'], queryFn: fetchMatieres })

  const { data, isLoading } = useQuery({
    queryKey: ['notes', classeCode, matiereCode],
    queryFn: () => fetchNotesConsultation({ classe_code: classeCode, matiere_code: matiereCode || undefined }),
    enabled: Boolean(classeCode),
  })

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-800">Notes</h1>
        <p className="mt-1 text-sm text-slate-500">Consultation (source : ECONOMAT).</p>
      </div>

      <div className="mb-6 grid max-w-lg grid-cols-2 gap-4">
        <Select label="Classe" value={classeCode} onChange={(e) => setClasseCode(e.target.value)}>
          <option value="">— Sélectionner —</option>
          {classes?.map((classe) => (
            <option key={classe.id} value={classe.code}>{classe.nom}</option>
          ))}
        </Select>
        <Select label="Matière (optionnel)" value={matiereCode} onChange={(e) => setMatiereCode(e.target.value)}>
          <option value="">Toutes</option>
          {matieres?.map((m) => (
            <option key={m.id} value={m.code}>{m.libelle}</option>
          ))}
        </Select>
      </div>

      {!classeCode && <p className="text-slate-400">Sélectionne une classe pour voir les notes.</p>}

      {classeCode && (
        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
              <tr>
                <th className="px-4 py-3 font-medium">Matricule</th>
                <th className="px-4 py-3 font-medium">Nom</th>
                <th className="px-4 py-3 font-medium">Prénom</th>
                <th className="px-4 py-3 font-medium">Matière</th>
                <th className="px-4 py-3 font-medium">Type</th>
                <th className="px-4 py-3 font-medium">Note</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {isLoading && (
                <tr><td colSpan={6} className="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>
              )}
              {!isLoading && data?.data?.length === 0 && (
                <tr><td colSpan={6} className="px-4 py-6 text-center text-slate-400">Aucune note.</td></tr>
              )}
              {data?.data?.map((n) => (
                <tr key={n.id} className="hover:bg-slate-50">
                  <td className="px-4 py-3 text-slate-600">{n.matricule}</td>
                  <td className="px-4 py-3 font-medium text-slate-800">{n.nom}</td>
                  <td className="px-4 py-3 text-slate-600">{n.prenom}</td>
                  <td className="px-4 py-3 text-slate-600">{n.matiere_libelle ?? n.matiere_code ?? '—'}</td>
                  <td className="px-4 py-3 text-slate-600">{n.type_note ?? '—'}</td>
                  <td className="px-4 py-3 font-medium text-slate-800">{n.note ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
