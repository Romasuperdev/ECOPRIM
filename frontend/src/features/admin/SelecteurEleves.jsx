import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { X } from 'lucide-react'
import Input from '../../components/ui/Input'
import { rechercherEleves } from './adminApi'

/**
 * Recherche et sélection d'un ou plusieurs élèves — utilisé pour rattacher les enfants
 * d'un compte du rôle Parent. Recherche par nom ou matricule (EleveController::index).
 */
export default function SelecteurEleves({ selection, onChange }) {
  const [recherche, setRecherche] = useState('')
  const { data: resultats } = useQuery({
    queryKey: ['recherche-eleves', recherche],
    queryFn: () => rechercherEleves(recherche),
    enabled: recherche.trim().length >= 2,
  })

  const ajouter = (eleve) => {
    if (! selection.some((e) => e.matricule === eleve.matricule)) {
      onChange([...selection, eleve])
    }
    setRecherche('')
  }
  const retirer = (matricule) => onChange(selection.filter((e) => e.matricule !== matricule))

  return (
    <div>
      <Input
        label="Élèves rattachés"
        placeholder="Rechercher par nom ou matricule…"
        value={recherche}
        onChange={(e) => setRecherche(e.target.value)}
      />
      {resultats?.length > 0 && (
        <div className="mt-1 max-h-40 overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-sm">
          {resultats.map((e) => (
            <button
              key={e.matricule} type="button" onClick={() => ajouter(e)}
              className="block w-full px-3 py-2 text-left text-sm hover:bg-slate-50"
            >
              {e.prenom} {e.nom} <span className="text-slate-400">({e.matricule})</span>
            </button>
          ))}
        </div>
      )}
      {selection.length > 0 && (
        <div className="mt-2 flex flex-wrap gap-1.5">
          {selection.map((e) => (
            <span key={e.matricule} className="flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-700">
              {`${e.prenom ?? ''} ${e.nom ?? ''}`.trim() || e.matricule}
              <button type="button" onClick={() => retirer(e.matricule)} className="text-slate-400 hover:text-red-500">
                <X size={12} />
              </button>
            </span>
          ))}
        </div>
      )}
    </div>
  )
}
