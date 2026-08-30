import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { GraduationCap } from 'lucide-react'
import Select from '../../components/ui/Select'
import { fetchEnseignants } from '../reference/referenceApi'
import DocumentsPanel from './DocumentsPanel'

export default function DocumentsEnseignantsPage() {
  const [enseignantId, setEnseignantId] = useState('')

  const { data: enseignants } = useQuery({ queryKey: ['enseignants', 'all'], queryFn: fetchEnseignants })

  const enseignant = enseignants?.find((en) => String(en.id) === String(enseignantId))

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-800">Documents enseignants</h1>
        <p className="mt-1 text-sm text-slate-500">
          Sélectionnez un enseignant pour consulter, ajouter ou télécharger ses documents.
        </p>
      </div>

      <div className="mb-6 max-w-md">
        <Select label="Enseignant" value={enseignantId} onChange={(e) => setEnseignantId(e.target.value)}>
          <option value="">— Sélectionner un enseignant —</option>
          {enseignants?.map((en) => (
            <option key={en.id} value={en.id}>
              {en.prenom} {en.nom}
              {en.matricule ? ` (${en.matricule})` : ''}
            </option>
          ))}
        </Select>
      </div>

      {enseignantId ? (
        <div>
          <p className="mb-3 text-sm font-medium text-slate-600">
            Documents de {enseignant?.prenom} {enseignant?.nom}
          </p>
          <DocumentsPanel documentableType="enseignant" documentableId={Number(enseignantId)} />
        </div>
      ) : (
        <div className="flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-200 bg-white py-16 text-center">
          <GraduationCap size={32} className="mb-3 text-slate-300" />
          <p className="text-sm text-slate-400">Choisissez un enseignant pour afficher ses documents.</p>
        </div>
      )}
    </div>
  )
}
