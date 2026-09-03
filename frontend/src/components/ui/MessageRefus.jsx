/**
 * Bandeau d'explication d'un refus de suppression (réponse 409).
 *
 * Le serveur renvoie un message qui nomme précisément ce qui bloque et combien — « 12
 * élèves y sont rattachés ». On l'affiche tel quel, plutôt qu'un alert() du navigateur
 * qui tronque et bloque la page.
 */
export default function MessageRefus({ message, onFermer }) {
  if (!message) return null

  return (
    <div className="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
      {message}
      {onFermer && (
        <button type="button" className="ml-2 underline" onClick={onFermer}>Fermer</button>
      )}
    </div>
  )
}
