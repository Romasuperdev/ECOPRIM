import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { MessageSquare, Mail } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Textarea from '../../components/ui/Textarea'
import { envoyer } from './communicationApi'

const LIMITE_SMS = 250

export default function EnvoiPage() {
  const [canal, setCanal] = useState('sms')
  const [brut, setBrut] = useState('')
  const [objet, setObjet] = useState('')
  const [message, setMessage] = useState('')
  const [resultat, setResultat] = useState(null)
  const [erreurs, setErreurs] = useState({})

  const destinataires = brut
    .split(/[\s,;]+/)
    .map((d) => d.trim())
    .filter(Boolean)

  const envoi = useMutation({
    mutationFn: () => envoyer({ canal, destinataires, message, objet: objet || undefined }),
    onSuccess: (r) => { setResultat(r); setErreurs({}); setMessage(''); setBrut(''); setObjet('') },
    onError: (e) => {
      setResultat(null)
      setErreurs(e?.response?.data?.errors ?? { _: [e?.response?.data?.message ?? 'Erreur'] })
    },
  })

  const estSms = canal === 'sms'
  const trop = estSms && message.length > LIMITE_SMS

  return (
    <div className="max-w-3xl">
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-800">Envoi SMS / Mail</h1>
        <p className="mt-1 text-sm text-slate-500">
          Transmettez une information aux parents ou au personnel, par SMS ou par courriel.
        </p>
      </div>

      {resultat && (
        <div className="mb-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
          {resultat.message}
          {resultat.echecs?.length > 0 && (
            <div className="mt-1 text-xs text-green-900/70">
              Adresses en échec : {resultat.echecs.join(', ')}
            </div>
          )}
        </div>
      )}

      {erreurs._ && (
        <div className="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          {erreurs._[0]}
        </div>
      )}

      <div className="space-y-5 rounded-xl border border-slate-200 bg-white p-6">
        {/* Canal */}
        <div>
          <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Canal</p>
          <div className="flex gap-3">
            {[
              { cle: 'sms', label: 'SMS', icone: MessageSquare },
              { cle: 'mail', label: 'Mail', icone: Mail },
            ].map(({ cle, label, icone: Icone }) => (
              <button
                key={cle}
                type="button"
                onClick={() => { setCanal(cle); setErreurs({}); setResultat(null) }}
                className={`flex flex-1 items-center justify-center gap-2 rounded-xl border px-4 py-3 text-sm font-semibold transition ${
                  canal === cle
                    ? 'border-primary-500 bg-primary-50 text-primary-700'
                    : 'border-slate-200 text-slate-500 hover:bg-slate-50'
                }`}
              >
                <Icone size={16} /> {label}
              </button>
            ))}
          </div>
        </div>

        <Textarea
          label={estSms ? 'Numéros de téléphone *' : 'Adresses email *'}
          rows={3}
          value={brut}
          onChange={(e) => setBrut(e.target.value)}
          placeholder={estSms ? '0700000000, 0501020304' : 'parent1@mail.com, parent2@mail.com'}
        />
        <p className="-mt-3 text-xs text-slate-400">
          Séparez par une virgule, un point-virgule ou un retour à la ligne.
          {destinataires.length > 0 && ` — ${destinataires.length} destinataire(s) détecté(s).`}
        </p>

        {!estSms && (
          <Input label="Objet" value={objet} onChange={(e) => setObjet(e.target.value)} error={erreurs.objet?.[0]}
                 placeholder="ex. Réunion de parents d’élèves" />
        )}

        <div>
          <Textarea label="Message *" rows={estSms ? 4 : 8} value={message}
                    onChange={(e) => setMessage(e.target.value)} error={erreurs.message?.[0]} />
          {estSms && (
            <p className={`mt-1 text-xs ${trop ? 'text-red-600' : 'text-slate-400'}`}>
              {message.length} / {LIMITE_SMS} caractères
              {trop && ' — trop long pour un SMS.'}
            </p>
          )}
        </div>

        <div className="flex items-center justify-between border-t border-slate-100 pt-4">
          <p className="text-xs text-slate-400">
            {estSms
              ? 'Les SMS sont déposés dans la file d’envoi et transmis par la passerelle configurée.'
              : 'Les mails partent immédiatement via le serveur SMTP configuré.'}
          </p>
          <Button
            disabled={envoi.isPending || destinataires.length === 0 || !message.trim() || trop}
            onClick={() => envoi.mutate()}
          >
            {envoi.isPending ? 'Envoi…' : `Envoyer ${destinataires.length ? `(${destinataires.length})` : ''}`}
          </Button>
        </div>
      </div>
    </div>
  )
}
