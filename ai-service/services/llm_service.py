from groq import Groq
from config import GROQ_API_KEY,GROQ_MODEL

client = Groq(api_key=GROQ_API_KEY)

def ask_llm_with_context(question: str, chunks: list) -> str:
    """
    Sends question + related document chunks to AI.
    """

    context = "\n\n".join(chunks)

    prompt = f"""
You are a helpful assistant.

Answer the user's question using only the context below.

If the answer is not available in the context, say:
"I could not find this information in the uploaded document."

Context:
{context}

Question:
{question}
"""

    response = client.chat.completions.create(
        model=GROQ_MODEL,
        messages=[
            {
                "role": "user",
                "content": prompt
            }
        ]
    )

    return response.choices[0].message.content