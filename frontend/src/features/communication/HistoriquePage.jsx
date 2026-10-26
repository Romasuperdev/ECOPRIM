import { useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import Input from '../../components/ui/Input'
import useDiffere from '../../hooks/useDiffere'
import { messageErreurApi } from '../../lib/erreurs'
import { fetchHistorique } from './communicationApi'

export default function HistoriquePage() {
  const [q, setQ] = useState('')
  // Recherche envoyée une fois la frappe posée, liste précédente gardée en attendant.
  const recherche = useDiffere(q.trim())
  const { data: lignes, isLoading, error } = useQuery({
    queryKey: ['historique-sms', recherche],
    queryFn: () => fetchHistorique(recherche),
    placeholderData: keepPreviousData,
  })

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-800">Historique des envois</h1>
        <p className="mt-1 text-sm text-slate-500">
          Journal des SMS transmis. L’historique n’est jamais effacé.
        </p>
      </div>

      <div className="mb-4 max-w-sm">
        <Input label="Rechercher" placeholder="Numéro ou contenu du message…" value={q}
               onChange={(e) => setQ(e.target.value)} />
      </div>

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-card">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Date</th>
              <th className="px-4 py-3 font-medium">Heure</th>
              <th className="px-4 py-3 font-medium">Numéro</th>
              <th className="px-4 py-3 font-medium">Message</th>
              <th className="px-4 py-3 font-medium">Type</th>
              <th className="px-4 py-3 font-medium">Émis par</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && <tr><td colSpan={6} className="px-4 py-6 text-center text-muted">Chargement…</td></tr>}
            {error && (
              <tr>
                <td colSpan={6} role="alert" className="px-4 py-6 text-center font-medium text-red-600">
                  {messageErreurApi(error, 'L’historique n’a pas pu être chargé.')}
                </td>
              </tr>
            )}
            {!isLoading && !error && lignes?.length === 0 && (
              <tr><td colSpan={6} className="px-4 py-6 text-center text-muted">Aucun envoi enregistré.</td></tr>
            )}
            {lignes?.map((l) => (
              <tr key={l.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 whitespace-nowrap text-slate-600">{l.date ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{l.heure ?? '—'}</td>
                <td className="px-4 py-3 font-mono text-xs text-slate-700">{l.numero ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{l.message}</td>
                <td className="px-4 py-3 text-slate-500">{l.type ?? '—'}</td>
                <td className="px-4 py-3 text-slate-500">{l.utilisateur ?? '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
